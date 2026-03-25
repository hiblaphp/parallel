<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Feature;

use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Exceptions\PoolShutdownException;
use Hibla\Parallel\Exceptions\RespawnRateLimitException;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

describe('Process Pool Max Respawn Per Second Test', function () {
    it('shuts down pool with RespawnRateLimitException when limit is exceeded', function () {
        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(1)
        ;

        await($pool->run(fn () => null));
        await($pool->run(fn () => null));

        $threw = false;

        try {
            await($pool->run(fn () => null));
        } catch (RespawnRateLimitException | PoolShutdownException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();

        await(delay(0.1));

        expect(fn () => await($pool->run(fn () => null)))
            ->toThrow(PoolShutdownException::class)
        ;

        $pool->shutdown();
    });

    it('does not shut down when respawns stay within the per-second limit', function () {
        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(10)
        ;

        $results = [];
        for ($i = 1; $i <= 5; $i++) {
            $results[] = await($pool->run(fn () => 'ok'));
        }

        expect($results)->toBe(['ok', 'ok', 'ok', 'ok', 'ok']);

        $pool->shutdown();
    });

    it('respawn counter resets after the one-second sliding window expires', function () {
        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(1)
        ;

        await($pool->run(fn () => null));

        await(delay(1.1));

        $result = await($pool->run(fn () => 'after window reset'));

        expect($result)->toBe('after window reset');

        $pool->shutdown();
    });

    it('pool with size > 1 counts each individual worker crash toward the limit', function () {
        $pool = Parallel::pool(2)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(2)
        ;

        $results = await(Promise::all([
            $pool->run(fn () => 'a'),
            $pool->run(fn () => 'b'),
        ]));

        expect($results)->toHaveCount(2);

        $pool->shutdown();
    });

    it('tasks submitted after pool shuts down due to rate limit are rejected', function () {
        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(1)
        ;

        await($pool->run(fn () => null));
        await($pool->run(fn () => null));

        await(delay(0.1));

        expect(fn () => await($pool->run(fn () => null)))
            ->toThrow(PoolShutdownException::class)
        ;

        expect(fn () => await($pool->run(fn () => 'should not run')))
            ->toThrow(PoolShutdownException::class)
        ;

        $pool->shutdown();
    });

    it('pool without rate limit configured never shuts down from respawns', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(1);

        $results = [];
        for ($i = 1; $i <= 8; $i++) {
            $results[] = await($pool->run(fn () => 'ok'));
        }

        expect($results)->toHaveCount(8);
        expect(array_unique($results))->toBe(['ok']);

        $pool->shutdown();
    });

    it('works correctly with lazy spawning', function () {
        $pool = Parallel::pool(1)
            ->withLazySpawning()
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(10)
        ;

        $results = await(Promise::all([
            $pool->run(fn () => 'x'),
            $pool->run(fn () => 'y'),
            $pool->run(fn () => 'z'),
        ]));

        expect($results)->toHaveCount(3);

        $pool->shutdown();
    });

    it('onWorkerRespawn callback still fires when rate limit has not been exceeded', function () {
        $respawnCount = 0;

        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->withMaxRestartPerSecond(10)
            ->onWorkerRespawn(function () use (&$respawnCount) {
                $respawnCount++;
            })
        ;

        await($pool->run(fn () => null));
        await($pool->run(fn () => null));
        await($pool->run(fn () => null));

        await(delay(0.3));

        expect($respawnCount)->toBe(3);

        $pool->shutdown();
    });

    it('throws InvalidArgumentException when max restarts per second is zero', function () {
        expect(fn () => Parallel::pool(2)->withMaxRestartPerSecond(0))
            ->toThrow(\InvalidArgumentException::class, 'Max restarts per second must be at least 1')
        ;
    });

    it('throws InvalidArgumentException when max restarts per second is negative', function () {
        expect(fn () => Parallel::pool(2)->withMaxRestartPerSecond(-1))
            ->toThrow(\InvalidArgumentException::class, 'Max restarts per second must be at least 1');
    });
});
