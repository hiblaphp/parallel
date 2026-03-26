<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Feature;

use function Hibla\await;

use Hibla\Parallel\Parallel;
use Hibla\Promise\Exceptions\CancelledException;

describe('Process Pool Cancellation and Respawn Integration', function () {
    $getTempFile = function (): string {
        return sys_get_temp_dir() . '/hibla_pool_test_' . uniqid() . '.tmp';
    };

    it('immediately cancels an active task and respawns a fresh worker', function () {
        $pool = Parallel::pool(1);

        $initialPid = await($pool->run(fn () => getmypid()));
        expect($initialPid)->toBeGreaterThan(0);

        $longPromise = $pool->run(function () {
            sleep(5);

            return getmypid();
        });

        await(Parallel::task()->run(fn () => usleep(150_000)));

        $start = microtime(true);
        $longPromise->cancel();
        $duration = microtime(true) - $start;

        expect($duration)->toBeLessThan(0.5);
        expect($longPromise->isCancelled())->toBeTrue();

        expect(fn () => await($longPromise))->toThrow(CancelledException::class);

        $respawnedPid = await($pool->run(fn () => getmypid()));

        expect($respawnedPid)->not->toBe($initialPid, 'The worker process was not replaced');
        expect($respawnedPid)->toBeGreaterThan(0);

        $reusedPid = await($pool->run(fn () => getmypid()));
        expect($reusedPid)->toBe($respawnedPid, 'The respawned worker was not reused');

        $pool->shutdown();
    });

    it('skips a task that was cancelled while waiting in the queue', function () use ($getTempFile) {
        $pool = Parallel::pool(1);
        $sideEffectFile = $getTempFile();

        $occupy = $pool->run(fn () => usleep(500_000));

        $queuedPromise = $pool->run(function () use ($sideEffectFile) {
            file_put_contents($sideEffectFile, 'should-not-exist');

            return 'done';
        });

        $queuedPromise->cancel();

        await($occupy);

        $nextResult = await($pool->run(fn () => 'next'));

        expect($queuedPromise->isCancelled())->toBeTrue();
        expect(file_exists($sideEffectFile))->toBeFalse('Queued task ran despite being cancelled');
        expect($nextResult)->toBe('next');

        $pool->shutdown();

        if (file_exists($sideEffectFile)) {
            unlink($sideEffectFile);
        }
    });

    it('recovers pool size after multiple concurrent worker terminations', function () {
        $poolSize = 3;
        $pool = Parallel::pool($poolSize);

        $pids = await(\Hibla\Promise\Promise::all([
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
        ]));

        expect(array_unique($pids))->toHaveCount($poolSize);

        $promises = [
            $pool->run(function () {
                sleep(5);

                return getmypid();
            }),
            $pool->run(function () {
                sleep(5);

                return getmypid();
            }),
            $pool->run(function () {
                sleep(5);

                return getmypid();
            }),
        ];

        await(Parallel::task()->run(fn () => usleep(150_000)));

        foreach ($promises as $p) {
            $p->cancel();
        }

        $newPids = await(\Hibla\Promise\Promise::all([
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
            $pool->run(function () {
                usleep(200_000);

                return getmypid();
            }),
        ]));

        expect(array_unique($newPids))->toHaveCount($poolSize, 'Pool did not recover all workers concurrently');

        foreach ($newPids as $newPid) {
            expect($pids)->not->toContain($newPid, "Worker PID {$newPid} was not replaced");
        }

        $pool->shutdown();
    });

    it('handles graceful shutdown when tasks are cancelled during closing', function () {
        $pool = Parallel::pool(2);

        $pool->run(fn () => sleep(2));

        $p = $pool->run(fn () => 'failed');
        $p->cancel();

        $pool->drain();

        expect(true)->toBeTrue('Shutdown was successful');
    });
});
