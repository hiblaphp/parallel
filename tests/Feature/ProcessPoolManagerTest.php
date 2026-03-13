<?php

declare(strict_types=1);

use function Hibla\await;

use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Managers\ProcessPoolManager;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Promise\Promise;
use Rcalicdan\Serializer\CallbackSerializationManager;

describe('ProcessPoolManager', function () {
    $buildPool = function (int $size) {
        $utils = new SystemUtilities();
        $serializer = new CallbackSerializationManager();
        $handler = new ProcessSpawnHandler($utils);

        return new ProcessPoolManager(
            size: $size,
            spawnHandler: $handler,
            serializer: $serializer,
            frameworkInfo: $utils->getFrameworkBootstrap(),
            memoryLimit: '128M',
            maxNestingLevel: 3
        );
    };

    it('resolves a submitted task with the correct return value', function () use ($buildPool) {
        $pool = $buildPool(2);

        $result = await($pool->submit(fn () => 'hello from pool', 5));

        expect($result)->toBe('hello from pool');

        $pool->shutdown();
    });

    it('runs multiple tasks concurrently and resolves all', function () use ($buildPool) {
        $pool = $buildPool(3);

        $results = await(Promise::all([
            $pool->submit(fn () => 'task-1', 5),
            $pool->submit(fn () => 'task-2', 5),
            $pool->submit(fn () => 'task-3', 5),
        ]));

        expect($results)->toBe(['task-1', 'task-2', 'task-3']);

        $pool->shutdown();
    });

    it('queues tasks when all workers are busy and processes them in order', function () use ($buildPool) {
        $pool = $buildPool(2);

        $results = await(Promise::all([
            $pool->submit(function () {
                usleep(100000);

                return 'task-1';
            }, 5),
            $pool->submit(function () {
                usleep(100000);

                return 'task-2';
            }, 5),
            $pool->submit(function () {
                usleep(100000);

                return 'task-3';
            }, 5),
            $pool->submit(function () {
                usleep(100000);

                return 'task-4';
            }, 5),
        ]));

        expect($results)->toHaveCount(4);
        expect($results)->toContain('task-1');
        expect($results)->toContain('task-2');
        expect($results)->toContain('task-3');
        expect($results)->toContain('task-4');

        $pool->shutdown();
    });

    it('reuses the same worker processes across multiple task batches', function () use ($buildPool) {
        $pool = $buildPool(2);

        $batch1 = await(Promise::all([
            $pool->submit(fn () => getmypid(), 5),
            $pool->submit(fn () => getmypid(), 5),
        ]));

        $batch2 = await(Promise::all([
            $pool->submit(fn () => getmypid(), 5),
            $pool->submit(fn () => getmypid(), 5),
        ]));

        $initialPids = array_unique($batch1);
        $reusedPids = array_unique($batch2);

        sort($initialPids);
        sort($reusedPids);

        expect($reusedPids)->toBe($initialPids, 'Worker PIDs changed between batches — workers were not reused');

        $pool->shutdown();
    });

    it('rejects the promise when the task throws an exception', function () use ($buildPool) {
        $pool = $buildPool(2);

        $results = await(Promise::allSettled([
            $pool->submit(function () {
                throw new RuntimeException('task failure');
            }, 5),
        ]));

        expect($results[0]->isRejected())->toBeTrue();
        expect($results[0]->reason->getMessage())->toBe('task failure');

        $pool->shutdown();
    });

    it('respawns a worker after it crashes and continues processing tasks', function () use ($buildPool) {
        $pool = $buildPool(2);

        [$crashed, $survived] = await(Promise::allSettled([
            $pool->submit(function () {
                exit(1);
            }, 5),
            $pool->submit(function () {
                usleep(300000);

                return 'survived';
            }, 5),
        ]));

        expect($crashed->isRejected())->toBeTrue();
        expect($survived->isFulfilled())->toBeTrue();
        expect($survived->value)->toBe('survived');

        usleep(800000);

        $recoveryResults = await(Promise::all([
            $pool->submit(fn () => getmypid(), 5),
            $pool->submit(fn () => getmypid(), 5),
        ]));

        expect(array_unique($recoveryResults))->toHaveCount(2, 'Expected 2 distinct PIDs after respawn');

        $pool->shutdown();
    });

    it('respawns all workers after a total pool wipeout', function () use ($buildPool) {
        $pool = $buildPool(2);

        [$a, $b] = await(Promise::allSettled([
            $pool->submit(function () {
                exit(1);
            }, 5),
            $pool->submit(function () {
                exit(1);
            }, 5),
        ]));

        expect($a->isRejected())->toBeTrue();
        expect($b->isRejected())->toBeTrue();

        usleep(800000);

        $results = await(Promise::all([
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
        ]));

        expect(array_unique($results))->toHaveCount(2, 'Expected 2 distinct PIDs — both workers should have respawned');

        $pool->shutdown();
    });

    it('drains the task queue using the respawned worker after a single-worker pool crash', function () use ($buildPool) {
        $pool = $buildPool(1);

        $crash = $pool->submit(function () {
            exit(1);
        }, 5);

        $queued1 = $pool->submit(fn () => 'queued-1', 5);
        $queued2 = $pool->submit(fn () => 'queued-2', 5);
        $queued3 = $pool->submit(fn () => 'queued-3', 5);
        [$crashResult, $r1, $r2, $r3] = await(Promise::allSettled([$crash, $queued1, $queued2, $queued3]));

        expect($crashResult->isRejected())->toBeTrue();
        expect($r1->isFulfilled())->toBeTrue();
        expect($r2->isFulfilled())->toBeTrue();
        expect($r3->isFulfilled())->toBeTrue();
        expect($r1->value)->toBe('queued-1');
        expect($r2->value)->toBe('queued-2');
        expect($r3->value)->toBe('queued-3');

        $pool->shutdown();
    });

    it('rejects tasks submitted after shutdown', function () use ($buildPool) {
        $pool = $buildPool(2);
        $pool->shutdown();

        $results = await(Promise::allSettled([
            $pool->submit(fn () => 'should not run', 5),
        ]));

        expect($results[0]->isRejected())->toBeTrue();
        expect($results[0]->reason->getMessage())->toContain('shutdown');
    });

    it('rejects all pending queued tasks when the pool is shut down', function () use ($buildPool) {
        $pool = $buildPool(1);

        $longTask = $pool->submit(function () {
            usleep(2000000);

            return 'long';
        }, 10);

        $queued1 = $pool->submit(fn () => 'q1', 10);
        $queued2 = $pool->submit(fn () => 'q2', 10);

        usleep(50000);
        $pool->shutdown();

        [$r1, $r2] = await(Promise::allSettled([$queued1, $queued2]));

        expect($r1->isRejected())->toBeTrue();
        expect($r1->reason->getMessage())->toContain('shut down');
        expect($r2->isRejected())->toBeTrue();
        expect($r2->reason->getMessage())->toContain('shut down');
    });

    it('correctly transports complex serialized return values through the pool', function () use ($buildPool) {
        $pool = $buildPool(2);

        $result = await($pool->submit(fn () => new DateTime('2025-01-15'), 5));

        expect($result)->toBeInstanceOf(DateTime::class);
        expect($result->format('Y-m-d'))->toBe('2025-01-15');

        $pool->shutdown();
    });

    it('never uses more worker processes than the configured pool size', function () use ($buildPool) {
        $pool = $buildPool(3);

        $results = await(Promise::all([
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
            $pool->submit(function () {
                usleep(200000);

                return getmypid();
            }, 5),
        ]));

        $uniquePids = array_unique($results);

        expect(count($uniquePids))->toBeLessThanOrEqual(3);

        $pool->shutdown();
    });

    it('allows active and queued tasks to finish when using shutdownAsync', function () use ($buildPool) {
        $pool = $buildPool(1);

        $p1 = $pool->submit(function () {
            usleep(200000);

            return 'task-1';
        }, 5);

        $p2 = $pool->submit(function () {
            usleep(200000);

            return 'task-2';
        }, 5);

        $shutdownPromise = $pool->shutdownAsync();

        $results = await(Promise::all([$p1, $p2, $shutdownPromise]));

        expect($results[0])->toBe('task-1');
        expect($results[1])->toBe('task-2');
        expect($results[2])->toBeNull();
    });

    it('rejects new tasks immediately if shutdownAsync has been called', function () use ($buildPool) {
        $pool = $buildPool(2);

        $pool->shutdownAsync();

        $result = await(Promise::allSettled([
            $pool->submit(fn () => 'too late', 5),
        ]));

        expect($result[0]->isRejected())->toBeTrue();
        expect($result[0]->reason->getMessage())->toContain('shutdown');
    });
});
