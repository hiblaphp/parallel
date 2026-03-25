<?php

declare(strict_types=1);

namespace Tests\Feature;

use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Parallel;

describe('Pool Boot', function () {
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

    it('boot() ensures getWorkerPids() returns actual PHP PIDs matching worker getmypid()', function () {
        $size = 2;
        $pool = Parallel::pool($size)->boot();

        $reportedPids = $pool->getWorkerPids();

        expect($reportedPids)->toHaveCount($size);

        $actualWorkerPid = await($pool->run(fn () => getmypid()));

        expect($reportedPids)->toContain($actualWorkerPid);

        $pool->shutdown();
    });

    it('populates worker PIDs only after boot() is called', function () {
        $pool = Parallel::pool(size: 2);

        expect($pool->getWorkerPids())->toBeEmpty();

        $pool->boot();

        $pids = $pool->getWorkerPids();
        expect($pids)->toHaveCount(2);
        foreach ($pids as $pid) {
            expect($pid)->toBeInt()->toBeGreaterThan(0);
        }

        $pool->shutdown();
    });

    it('boot() pre-warms workers so the first run() dispatches without spawn latency', function () {
        $pool = Parallel::pool(size: 2)->boot();

        $start = microtime(true);
        $result = await($pool->run(fn () => 'booted'));
        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(0.1);
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
});
