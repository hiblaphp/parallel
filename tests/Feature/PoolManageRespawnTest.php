<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Integration;

use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\Parallel;

use function Hibla\await;
use function Hibla\delay;

describe('Pool Manage Respawn Test', function () {

    it('triggers onWorkerRespawn when a worker crashes', function () {
        $respawnTriggered = false;

        $pool = Parallel::pool(1)->onWorkerRespawn(function (ProcessPoolInterface $pool) use (&$respawnTriggered) {
            $respawnTriggered = true;
        });

        $pool->run(fn () => exit(1))->catch(fn () => null);

        await(delay(0.3));

        expect($respawnTriggered)->toBeTrue();
        $pool->shutdown();
    });

    it('triggers onWorkerRespawn when a worker retires', function () {
        $respawnCount = 0;

        $pool = Parallel::pool(1)
            ->withMaxExecutionsPerWorker(1)
            ->onWorkerRespawn(function (ProcessPoolInterface $pool) use (&$respawnCount) {
                $respawnCount++;
            })
        ;

        await($pool->run(fn () => 'task 1'));

        await(delay(0.3));

        expect($respawnCount)->toBe(1);
        $pool->shutdown();
    });

    it('injects the pool instance into the respawn callback', function () {
        $injectedInstance = null;

        $pool = Parallel::pool(1)->onWorkerRespawn(function (ProcessPoolInterface $p) use (&$injectedInstance) {
            $injectedInstance = $p;
        });

        $pool->run(fn () => exit(1))->catch(fn () => null);
        await(delay(0.3));

        expect($injectedInstance)->toBeInstanceOf(ProcessPoolInterface::class);
        expect($injectedInstance->getWorkerCount())->toBe(1);

        $pool->shutdown();
    });

    it('successfully re-submits and executes a task within the respawn hook', function () {
        $hookResult = null;

        $pool = Parallel::pool(1)->onWorkerRespawn(function (ProcessPoolInterface $p) use (&$hookResult) {
            $p->run(fn () => 'task-from-hook')->then(function ($res) use (&$hookResult) {
                $hookResult = $res;
            });
        });

        $pool->run(fn () => exit(1))->catch(fn () => null);

        await(delay(0.6));

        expect($hookResult)->toBe('task-from-hook');
        $pool->shutdown();
    });

    it('stays active across multiple consecutive crashes', function () {
        $respawnCount = 0;

        $pool = Parallel::pool(1)->onWorkerRespawn(function (ProcessPoolInterface $p) use (&$respawnCount) {
            $respawnCount++;
        });

        $pool->run(fn () => exit(1))->catch(fn () => null);
        await(delay(0.3));

        $pool->run(fn () => exit(1))->catch(fn () => null);
        await(delay(0.3));

        expect($respawnCount)->toBe(2);
        $pool->shutdown();
    });

    it('onWorkerRespawn() is immutable and returns a new instance', function () {
        $pool1 = Parallel::pool(1);
        $pool2 = $pool1->onWorkerRespawn(fn () => null);

        $ref1 = new \ReflectionObject($pool1);
        $prop1 = $ref1->getProperty('onRespawnHandler');

        $ref2 = new \ReflectionObject($pool2);
        $prop2 = $ref2->getProperty('onRespawnHandler');

        expect($pool1)->not->toBe($pool2);
        expect($prop1->getValue($pool1))->toBeNull();
        expect($prop2->getValue($pool2))->not->toBeNull();

        $pool1->shutdown();
        $pool2->shutdown();
    });

    it('does not leak memory when the pool is destroyed (WeakReference check)', function () {
        $respawned = false;

        $pool = Parallel::pool(1)->onWorkerRespawn(function () use (&$respawned) {
            $respawned = true;
        });

        $pool->run(fn () => exit(1))->catch(fn () => null);
        $pool->shutdown();
        $pool = null;

        await(delay(0.3));

        expect($respawned)->toBeFalse();
    });

    it('can be combined with onMessage and other pool configurations', function () {
        $messages = [];
        $respawns = 0;

        $pool = Parallel::pool(1)
            ->onMessage(function ($msg) use (&$messages) {
                $messages[] = $msg->data;
            })
            ->onWorkerRespawn(function () use (&$respawns) {
                $respawns++;
            })
        ;

        await($pool->run(fn () => \Hibla\emit('hello')));

        $pool->run(fn () => exit(1))->catch(fn () => null);
        await(delay(0.3));

        expect($messages)->toContain('hello');
        expect($respawns)->toBe(1);

        $pool->shutdown();
    });
});
