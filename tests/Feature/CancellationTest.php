<?php

use Hibla\EventLoop\Loop;
use Hibla\Parallel\Managers\ProcessManager;
use Rcalicdan\ConfigLoader\Config;
use function Hibla\parallel;

describe('Process Cancellation Integration', function () {

    afterEach(function () {
        ProcessManager::setGlobal(null);
        Config::reset();
        Loop::reset();
    });

    it('cancels a running task via Loop::addTimer() and finishes early', function () {
        $start = microtime(true);

        $promise = parallel(fn() => usleep(5000000));

        Loop::addTimer(1.0, function () use ($promise) {
            $promise->cancel();
        });

        Loop::run();

        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThan(0.95);

        expect($duration)->toBeLessThan(2.0);

        expect($promise->isCancelled())->toBeTrue();
        expect($promise->isFulfilled())->toBeFalse();
    });

    it('ensures resources are cleaned up (Process Killed) upon cancellation', function () {
        $file = sys_get_temp_dir() . '/hibla_loop_cancel_' . uniqid();

        $promise = parallel(function () use ($file) {
            sleep(3);
            file_put_contents($file, 'I should not exist');
        });

        Loop::addTimer(0.5, function () use ($promise) {
            $promise->cancel();
        });

        Loop::run();

        usleep(2600000);

        expect(file_exists($file))->toBeFalse();

        if (file_exists($file)) {
            @unlink($file);
        }
    });
});
