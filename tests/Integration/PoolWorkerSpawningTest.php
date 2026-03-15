<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Integration;

use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Exceptions\TimeoutException;

use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

describe('Pool Worker Spawning Test', function () {
    it('eager pool pre-spawns all workers after first task', function () {
        $pool = Parallel::pool(3);

        await($pool->run(fn () => null));

        expect($pool->getWorkerCount())->toBe(3);
        expect($pool->getWorkerPids())->toHaveCount(3);

        $pool->shutdown();
    });

    it('eager pool dispatches first task with near-zero latency after warmup', function () {
        $pool = Parallel::pool(2);

        await($pool->run(fn () => null));
        await(delay(0.3));

        $start = microtime(true);
        await($pool->run(fn () => 'result'));
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.2);

        $pool->shutdown();
    });

    it('eager pool worker PIDs are valid positive integers', function () {
        $pool = Parallel::pool(3);

        await($pool->run(fn () => null));

        $pids = $pool->getWorkerPids();

        expect($pids)->toHaveCount(3);

        foreach ($pids as $pid) {
            expect($pid)->toBeInt();
            expect($pid)->toBeGreaterThan(0);
        }

        $pool->shutdown();
    });

    it('eager pool worker PIDs are all unique', function () {
        $pool = Parallel::pool(4);

        await($pool->run(fn () => null));

        $pids = $pool->getWorkerPids();
        $uniquePids = array_unique($pids);

        expect(\count($uniquePids))->toBe(4);

        $pool->shutdown();
    });

    it('eager pool executes tasks correctly', function () {
        $pool = Parallel::pool(2);

        $results = await(Promise::all([
            $pool->run(fn () => 'task-1'),
            $pool->run(fn () => 'task-2'),
        ]));

        expect($results)->toBe(['task-1', 'task-2']);

        $pool->shutdown();
    });

    it('lazy pool reports zero workers before first submit', function () {
        $pool = Parallel::pool(5)->withLazySpawning();

        expect($pool->getWorkerCount())->toBe(0);
        expect($pool->getWorkerPids())->toBe([]);

        $pool->shutdown();
    });

    it('lazy pool that is never used spawns zero workers', function () {
        $start = microtime(true);

        $pool = Parallel::pool(5)->withLazySpawning();
        $elapsed = microtime(true) - $start;

        expect($pool->getWorkerCount())->toBe(0);
        expect($pool->getWorkerPids())->toBe([]);

        expect($elapsed)->toBeLessThan(0.01);

        $pool->shutdown();

        expect($pool->getWorkerCount())->toBe(0);
        expect($pool->getWorkerPids())->toBe([]);
    });

    it('lazy pool spawns exactly one worker for one task', function () {
        $pool = Parallel::pool(5)->withLazySpawning();

        await($pool->run(fn () => null));

        expect($pool->getWorkerCount())->toBe(1);
        expect($pool->getWorkerPids())->toHaveCount(1);
        expect($pool->getWorkerPids()[0])->toBeGreaterThan(0);

        $pool->shutdown();
    });

    it('lazy pool spawns exactly N workers for N concurrent tasks', function () {
        $pool = Parallel::pool(5)->withLazySpawning();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        expect($pool->getWorkerCount())->toBe(3);
        expect($pool->getWorkerPids())->toHaveCount(3);

        $pool->shutdown();
    });

    it('lazy pool never exceeds configured pool size', function () {
        $pool = Parallel::pool(3)->withLazySpawning();

        $results = await(Promise::all([
            $pool->run(fn () => 'a'),
            $pool->run(fn () => 'b'),
            $pool->run(fn () => 'c'),
            $pool->run(fn () => 'd'),
            $pool->run(fn () => 'e'),
        ]));

        expect(\count($results))->toBe(5);
        expect($pool->getWorkerCount())->toBe(3);
        expect($pool->getWorkerPids())->toHaveCount(3);

        $pool->shutdown();
    });

    it('lazy pool grows incrementally with task submissions', function () {
        $pool = Parallel::pool(5)->withLazySpawning();

        expect($pool->getWorkerCount())->toBe(0);

        await($pool->run(fn () => null));
        expect($pool->getWorkerCount())->toBe(1);
        expect($pool->getWorkerPids())->toHaveCount(1);

        await($pool->run(fn () => null));
        expect($pool->getWorkerCount())->toBe(2);
        expect($pool->getWorkerPids())->toHaveCount(2);

        await($pool->run(fn () => null));
        expect($pool->getWorkerCount())->toBe(3);
        expect($pool->getWorkerPids())->toHaveCount(3);

        $pool->shutdown();
    });

    it('lazy pool worker PIDs are valid positive integers', function () {
        $pool = Parallel::pool(3)->withLazySpawning();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        foreach ($pool->getWorkerPids() as $pid) {
            expect($pid)->toBeInt();
            expect($pid)->toBeGreaterThan(0);
        }

        $pool->shutdown();
    });

    it('lazy pool worker PIDs are all unique', function () {
        $pool = Parallel::pool(3)->withLazySpawning();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        $pids = $pool->getWorkerPids();
        $uniquePids = array_unique($pids);

        expect(\count($uniquePids))->toBe(3);

        $pool->shutdown();
    });

    it('lazy pool reuses workers across multiple batches', function () {
        $pool = Parallel::pool(2)->withLazySpawning();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        $pidsAfterBatch1 = $pool->getWorkerPids();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        $pidsAfterBatch2 = $pool->getWorkerPids();

        sort($pidsAfterBatch1);
        sort($pidsAfterBatch2);

        // Worker count stays at 2 — no new workers spawned for batch 2
        expect($pool->getWorkerCount())->toBe(2);

        // Same worker PIDs after both batches — workers were reused
        expect($pidsAfterBatch2)->toBe($pidsAfterBatch1);

        $pool->shutdown();
    });

    it('lazy pool respects timeout', function () {
        $pool = Parallel::pool(2)
            ->withLazySpawning()
            ->withTimeout(1)
        ;

        await($pool->run(fn () => sleep(5)));
    })->throws(TimeoutException::class);

    it('lazy pool respects memory limit', function () {
        $pool = Parallel::pool(2)
            ->withLazySpawning()
            ->withMemoryLimit('16M')
        ;

        await($pool->run(fn () => str_repeat('a', 32 * 1024 * 1024)));
    })->throws(\Exception::class, 'Allowed memory size');

    it('lazy pool rejects tasks after shutdown', function () {
        $pool = Parallel::pool(2)->withLazySpawning();
        $pool->shutdown();

        $results = await(Promise::allSettled([
            $pool->run(fn () => 'should not run'),
        ]));

        expect($results[0]->isRejected())->toBeTrue();
        expect($results[0]->reason->getMessage())->toContain('shutdown');
    });

    it('eager pool reports zero workers after shutdown', function () {
        $pool = Parallel::pool(3);

        await($pool->run(fn () => null));

        expect($pool->getWorkerCount())->toBe(3);

        $pool->shutdown();

        expect($pool->getWorkerCount())->toBe(0);
        expect($pool->getWorkerPids())->toBe([]);
    });

    it('lazy pool reports zero workers after shutdown', function () {
        $pool = Parallel::pool(3)->withLazySpawning();

        await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        expect($pool->getWorkerCount())->toBe(2);

        $pool->shutdown();

        expect($pool->getWorkerCount())->toBe(0);
        expect($pool->getWorkerPids())->toBe([]);
    });

    it('withLazySpawning() is immutable and does not affect original instance', function () {
        $base = Parallel::pool(3);
        $lazy = Parallel::pool(3)->withLazySpawning();

        expect($base)->not->toBe($lazy);

        await($base->run(fn () => null));
        await($lazy->run(fn () => null));

        expect($base->getWorkerCount())->toBe(3);
        expect($lazy->getWorkerCount())->toBe(1);

        $basePids = $base->getWorkerPids();
        $lazyPids = $lazy->getWorkerPids();

        expect(array_intersect($basePids, $lazyPids))->toBe([]);

        $base->shutdown();
        $lazy->shutdown();
    });

    it('withLazySpawning() can be chained with other configuration methods', function () {
        $pool = Parallel::pool(3)
            ->withLazySpawning()
            ->withTimeout(30)
            ->withMemoryLimit('256M')
            ->withoutTimeout()
        ;

        expect($pool->getWorkerCount())->toBe(0);

        $result = await($pool->run(fn () => 'chained'));

        expect($result)->toBe('chained');
        expect($pool->getWorkerCount())->toBe(1);

        $pool->shutdown();
    });
});
