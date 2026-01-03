<?php

use Hibla\Promise\Promise;
use Hibla\Promise\SettledResult;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Exceptions\AggregateErrorException;

use function Hibla\parallel;
use function Hibla\async;
use function Hibla\await;

describe('Promise Combinators Integration', function () {
    it('Promise::all: waits for multiple parallel tasks to complete successfully', function () {
        $start = microtime(true);

        $promises = [
            parallel(fn() => 10),
            parallel(fn() => 20),
            parallel(function () {
                usleep(200000); 
                return 30;
            })
        ];

        $results = await(Promise::all($promises));
        $duration = microtime(true) - $start;

        expect($results)->toBe([10, 20, 30]);

        expect($duration)->toBeLessThan(2.0);
    });

    it('Promise::all: rejects immediately if one task fails', function () {
        $promises = [
            parallel(function () {
                usleep(100000);
                return 'slow';
            }),
            parallel(fn() => throw new Exception('Fail Fast')),
        ];

        expect(fn() => await(Promise::all($promises)))
            ->toThrow(Exception::class, 'Fail Fast');
    });

    it('Promise::allSettled: waits for all tasks regardless of outcome', function () {
        $promises = [
            parallel(fn() => 'Success'),
            parallel(fn() => throw new RuntimeException('Oops')),
            parallel(fn() => 'Another Success')
        ];

        $results = await(Promise::allSettled($promises));

        expect($results)->toBeArray()->toHaveCount(3);

        expect($results[0])->toBeInstanceOf(SettledResult::class);
        expect($results[0]->isFulfilled())->toBeTrue();
        expect($results[0]->value)->toBe('Success');

        expect($results[1])->toBeInstanceOf(SettledResult::class);
        expect($results[1]->isRejected())->toBeTrue();
        expect($results[1]->reason)->toBeInstanceOf(RuntimeException::class);
        expect($results[1]->reason->getMessage())->toBe('Oops');

        expect($results[2])->toBeInstanceOf(SettledResult::class);
        expect($results[2]->isFulfilled())->toBeTrue();
        expect($results[2]->value)->toBe('Another Success');
    });

    it('Promise::race: resolves with the fastest task', function () {
        $fast = parallel(function () {
            usleep(100000); 
            return 'Fast';
        });

        $slow = parallel(function () {
            usleep(500000); 
            return 'Slow';
        });

        $start = microtime(true);
        $winner = await(Promise::race([$fast, $slow]));
        $duration = microtime(true) - $start;

        expect($winner)->toBe('Fast');

        expect($duration)->toBeLessThan(2.0);
    });

    it('Promise::any: resolves with the first successful task (ignoring failures)', function () {
        $promises = [
            parallel(fn() => throw new Exception('Bad 1')),

            parallel(function () {
                usleep(50000);
                throw new Exception('Bad 2');
            }),

            parallel(function () {
                usleep(150000);
                return 'Winner';
            })
        ];

        $result = await(Promise::any($promises));

        expect($result)->toBe('Winner');
    });

    it('Promise::any: throws AggregateException if all tasks fail', function () {
        $promises = [
            parallel(fn() => throw new Exception('Err 1')),
            parallel(fn() => throw new Exception('Err 2')),
        ];

        try {
            await(Promise::any($promises));
        } catch (AggregateErrorException $e) {
            $exceptions = method_exists($e, 'getExceptions') ? $e->getExceptions() : $e->getErrors();
            
            expect($exceptions)->toHaveCount(2);
            expect($exceptions[0]->getMessage())->toBe('Err 1');
            return;
        }

        $this->fail('AggregateErrorException was not thrown');
    });

    it('Promise::timeout: cancels task if it takes too long', function () {
        $slowTask = parallel(function () {
            sleep(2);
            return 'Too Late';
        });

        $promise = Promise::timeout($slowTask, 0.2);

        try {
            await($promise);
        } catch (TimeoutException $e) {
            expect($e->getMessage())->toContain('timed out');
            return;
        }

        $this->fail('TimeoutException was not thrown');
    });

    it('integration: combinators work seamlessly inside async() fibers', function () {
        $finalResult = await(async(function () {
            $batch1 = await(Promise::all([
                parallel(fn() => 1),
                parallel(fn() => 2)
            ]));
            $sum = array_sum($batch1);

            $result = await(parallel(fn() => $sum * 10));

            return $result;
        }));

        expect($finalResult)->toBe(30);
    });
});