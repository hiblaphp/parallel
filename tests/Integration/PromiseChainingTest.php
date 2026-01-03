<?php

use Hibla\EventLoop\Loop;
use function Hibla\parallel;
use function Hibla\await;

describe('Promise Chaining Integration', function () {
    it('chains multiple .then() calls to transform a successful result', function () {
        $promise = parallel(fn() => 5)
            ->then(fn(int $value) => $value * 10)
            ->then(fn(int $value) => $value + 3);

        $result = await($promise);

        expect($result)->toBe(53);
    });

    it('can chain from a parallel task to another parallel task', function () {
        $promise = parallel(fn() => 'user_id_123')
            ->then(function(string $userId) {
                return parallel(fn() => "Data for $userId");
            });

        $result = await($promise);

        expect($result)->toBe('Data for user_id_123');
    });

    it('handles a worker exception with a .catch() block', function () {
        $promise = parallel(function() {
            throw new \RuntimeException("Worker failed!");
        })
        ->catch(function(\Throwable $e) {
            return "Recovered from: " . $e->getMessage();
        });

        $result = await($promise);

        expect($result)->toBe("Recovered from: Worker failed!");
    });


    it('executes a .finally() block on successful resolution', function () {
        $finallyWasCalled = false;

        $promise = parallel(fn() => 'Success')
            ->finally(function() use (&$finallyWasCalled) {
                $finallyWasCalled = true;
            });

        $result = await($promise);

        expect($result)->toBe('Success');
        expect($finallyWasCalled)->toBeTrue();
    });

    it('executes a .finally() block on rejection', function () {
        $finallyWasCalled = false;

        $promise = parallel(fn() => throw new \Exception('Failure'))
            ->finally(function() use (&$finallyWasCalled) {
                $finallyWasCalled = true;
            });

        try {
            await($promise);
        } catch (\Exception $e) {
            expect($e->getMessage())->toBe('Failure');
        }

        expect($finallyWasCalled)->toBeTrue();
    });
    
    it('propagates cancellation up the promise chain', function () {
        $start = microtime(true);
        $onCancelWasCalled = false;

        $basePromise = parallel(fn() => sleep(5));
        
        $chainedPromise = $basePromise
            ->then(fn() => 'Should not be called')
            ->onCancel(function() use (&$onCancelWasCalled) {
                $onCancelWasCalled = true;
            });

        Loop::addTimer(0.5, function() use ($chainedPromise) {
            $chainedPromise->cancelChain();
        });

        Loop::run();

        $duration = microtime(true) - $start;

        expect($duration)->toBeLessThan(2.0);
        expect($onCancelWasCalled)->toBeTrue();
        expect($basePromise->isCancelled())->toBeTrue();
    });
});