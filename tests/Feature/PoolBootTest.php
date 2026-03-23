<?php

declare(strict_types=1);

namespace Tests\Feature;

use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Exceptions\PoolShutdownException;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use Rcalicdan\ConfigLoader\Config;

describe('Pool Boot & bootAsync Feature Test', function () {
    afterEach(function () {
        Config::reset();
        ProcessManager::setGlobal(null);
    });

    it('boot() returns the same pool instance for fluent chaining', function () {
        $pool = Parallel::pool(size: 2);
        $returned = $pool->boot();

        expect($returned)->toBe($pool);

        $pool->shutdown();
    });

    it('boot() is idempotent — calling it multiple times is a no-op', function () {
        $pool = Parallel::pool(size: 2);

        $pool->boot();
        $pool->boot();
        $pool->boot();

        $result = await($pool->run(fn () => 'still works'));

        expect($result)->toBe('still works');

        $pool->shutdown();
    });

    it('boot() pre-warms workers so the first run() dispatches without spawn latency', function () {
        $pool = Parallel::pool(size: 2)->boot();

        await(delay(0.5));

        $start = microtime(true);
        $result = await($pool->run(fn () => 'booted'));
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.5);
        expect($result)->toBe('booted');

        $pool->shutdown();
    });

    it('boot() workers survive idle time and are still usable after several seconds', function () {
        $pool = Parallel::pool(size: 2)->boot();

        await(delay(2));

        $result = await($pool->run(fn () => 'survived idle'));

        expect($result)->toBe('survived idle');

        $pool->shutdown();
    });


    it('bootAsync() returns a PromiseInterface resolving to the same pool instance', function () {
        $pool = Parallel::pool(size: 2);
        $resolved = await($pool->bootAsync());

        expect($resolved)->toBe($pool);

        $pool->shutdown();
    });

    it('bootAsync() resolves only after all workers have sent READY frames', function () {
        $pool = await(Parallel::pool(size: 4)->bootAsync());

        $pids = $pool->getWorkerPids();

        expect($pids)->toHaveCount(4);
        foreach ($pids as $pid) {
            expect($pid)->toBeInt()->toBeGreaterThan(0);
        }

        $pool->shutdown();
    });

    it('bootAsync() PIDs match getmypid() reported inside worker tasks', function () {
        $pool = await(Parallel::pool(size: 4)->bootAsync());

        $bootPids = $pool->getWorkerPids();
        sort($bootPids);

        $taskPids = await(Promise::all([
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
        ]));

        $taskPids = array_unique($taskPids);
        sort($taskPids);

        expect($taskPids)->toBe($bootPids);

        $pool->shutdown();
    });

    it('bootAsync() PIDs remain stable over time without submitting tasks', function () {
        $pool = await(Parallel::pool(size: 3)->bootAsync());

        $pidsAtBoot = $pool->getWorkerPids();
        sort($pidsAtBoot);

        await(delay(1));
        $pidsAfter1s = $pool->getWorkerPids();
        sort($pidsAfter1s);

        await(delay(1));
        $pidsAfter2s = $pool->getWorkerPids();
        sort($pidsAfter2s);

        expect($pidsAfter1s)->toBe($pidsAtBoot);
        expect($pidsAfter2s)->toBe($pidsAtBoot);

        $pool->shutdown();
    });

    it('bootAsync() is idempotent — calling it multiple times resolves to the same instance', function () {
        $pool = Parallel::pool(size: 2);

        $first  = await($pool->bootAsync());
        $second = await($pool->bootAsync());
        $third  = await($pool->bootAsync());

        expect($first)->toBe($pool);
        expect($second)->toBe($pool);
        expect($third)->toBe($pool);

        $result = await($pool->run(fn () => 'idempotent'));
        expect($result)->toBe('idempotent');

        $pool->shutdown();
    });

    it('bootAsync() after boot() resolves immediately since workers are already spawning', function () {
        $pool = Parallel::pool(size: 2)->boot();

        $resolved = await($pool->bootAsync());

        expect($resolved)->toBe($pool);
        expect($pool->getWorkerPids())->toHaveCount(2);

        $pool->shutdown();
    });

    it('bootAsync() workers are immediately usable with no additional latency', function () {
        $pool = await(Parallel::pool(size: 2)->bootAsync());

        $start   = microtime(true);
        $result  = await($pool->run(fn () => 'instant'));
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.5);
        expect($result)->toBe('instant');

        $pool->shutdown();
    });

    it('bootAsync() worker count equals pool size after resolution', function () {
        $pool = await(Parallel::pool(size: 3)->bootAsync());

        expect($pool->getWorkerCount())->toBe(3);

        $pool->shutdown();
    });

    it('bootAsync() on a lazy pool resolves immediately without spawning workers', function () {
        $pool = Parallel::pool(size: 2)->withLazySpawning();

        $start    = microtime(true);
        $resolved = await($pool->bootAsync());
        $elapsed  = microtime(true) - $start;

        expect($resolved)->toBe($pool);
        expect($elapsed)->toBeLessThan(0.1);

        $pool->shutdown();
    });

    it('lazy pool spawns workers on first run() after bootAsync()', function () {
        $pool = await(
            Parallel::pool(size: 2)
                ->withLazySpawning()
                ->bootAsync()
        );

        expect($pool->getWorkerCount())->toBe(0);

        $result = await($pool->run(fn () => 'lazy worker'));

        expect($result)->toBe('lazy worker');
        expect($pool->getWorkerCount())->toBeGreaterThan(0);

        $pool->shutdown();
    });

    it('bootAsync() boot promise is rejected when pool is shut down before workers finish booting', function () {
        $pool = Parallel::pool(size: 2);

        $bootPromise = $pool->bootAsync();
        $pool->shutdown();

        $results = await(Promise::allSettled([$bootPromise]));

        expect($results[0]->isRejected())->toBeTrue();
        expect($results[0]->reason)->toBeInstanceOf(PoolShutdownException::class);
    });


    it('full fluent chain with bootAsync() works end-to-end', function () {
        $pool = await(
            Parallel::pool(size: 2)
                ->withTimeout(30)
                ->withMemoryLimit('256M')
                ->bootAsync()
        );

        $results = await(Promise::all([
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
        ]));

        $pids = $pool->getWorkerPids();

        expect(array_unique($results))->toHaveCount(2);
        expect(array_diff($pids, $results))->toBeEmpty();

        $pool->shutdown();
    });
});