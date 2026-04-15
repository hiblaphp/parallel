<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Integration;

use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;
use function Hibla\emit;

describe('Process Pool Max Execution Test', function () {
    it('worker retires after reaching max executions', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(3);

        $pid1 = await($pool->run(fn () => getmypid()));
        $pid2 = await($pool->run(fn () => getmypid()));
        $pid3 = await($pool->run(fn () => getmypid()));

        expect($pid1)->toBe($pid2);
        expect($pid2)->toBe($pid3);

        $pid4 = await($pool->run(fn () => getmypid()));

        expect($pid4)->not->toBe($pid1);

        $pool->shutdown();
    });

    it('replacement worker is fully functional after retirement', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(2);

        await($pool->run(fn () => null));
        await($pool->run(fn () => null));

        $result = await($pool->run(fn () => 'replacement works'));

        expect($result)->toBe('replacement works');

        $pool->shutdown();
    });

    it('worker executes exactly max executions before retiring', function () {
        $maxExecutions = 5;
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker($maxExecutions);

        $pids = [];
        for ($i = 0; $i < $maxExecutions; $i++) {
            $pids[] = await($pool->run(fn () => getmypid()));
        }

        $uniquePids = array_unique($pids);
        expect(\count($uniquePids))->toBe(1);

        $retirementPid = await($pool->run(fn () => getmypid()));
        expect($retirementPid)->not->toBe($pids[0]);

        $pool->shutdown();
    });

    it('pool worker count stays stable through multiple retirements', function () {
        $pool = Parallel::pool(2)->withMaxExecutionsPerWorker(3);

        $results = await(Promise::all([
            $pool->run(fn () => 'a'),
            $pool->run(fn () => 'b'),
            $pool->run(fn () => 'c'),
            $pool->run(fn () => 'd'),
            $pool->run(fn () => 'e'),
            $pool->run(fn () => 'f'),
            $pool->run(fn () => 'g'),
            $pool->run(fn () => 'h'),
        ]));

        expect($results)->toHaveCount(8);

        await(delay(0.3));

        expect($pool->getWorkerCount())->toBe(2);

        $pool->shutdown();
    });

    it('tasks return correct results through worker retirements', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(2);

        $results = [];
        for ($i = 1; $i <= 6; $i++) {
            $results[] = await($pool->run(function () use ($i) {
                return $i;
            }));
        }

        expect($results)->toBe([1, 2, 3, 4, 5, 6]);

        $pool->shutdown();
    });

    it('message passing works correctly through worker retirements', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(2);
        $received = [];

        await($pool->run(
            callback: function () {
                emit(['worker' => 'first', 'task' => 1]);

                return 'task-1';
            },
            onMessage: function (\Hibla\Parallel\ValueObjects\WorkerMessage $msg) use (&$received) {
                $received[] = $msg->data;
            }
        ));

        await($pool->run(
            callback: function () {
                emit(['worker' => 'first', 'task' => 2]);

                return 'task-2';
            },
            onMessage: function (\Hibla\Parallel\ValueObjects\WorkerMessage $msg) use (&$received) {
                $received[] = $msg->data;
            }
        ));

        await($pool->run(
            callback: function () {
                emit(['worker' => 'replacement', 'task' => 3]);

                return 'task-3';
            },
            onMessage: function (\Hibla\Parallel\ValueObjects\WorkerMessage $msg) use (&$received) {
                $received[] = $msg->data;
            }
        ));

        expect($received)->toHaveCount(3);
        expect($received[0])->toBe(['worker' => 'first', 'task' => 1]);
        expect($received[1])->toBe(['worker' => 'first', 'task' => 2]);
        expect($received[2])->toBe(['worker' => 'replacement', 'task' => 3]);

        $pool->shutdown();
    });

    it('worker does not retire when max executions is not configured', function () {
        $pool = Parallel::pool(1);

        $pids = [];
        for ($i = 0; $i < 10; $i++) {
            $pids[] = await($pool->run(fn () => getmypid()));
        }

        $uniquePids = array_unique($pids);
        expect(\count($uniquePids))->toBe(1);

        $pool->shutdown();
    });

    it('max executions of 1 retires worker after every single task', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(1);

        $pid1 = await($pool->run(fn () => getmypid()));
        $pid2 = await($pool->run(fn () => getmypid()));
        $pid3 = await($pool->run(fn () => getmypid()));

        expect($pid1)->not->toBe($pid2);
        expect($pid2)->not->toBe($pid3);
        expect($pid1)->not->toBe($pid3);

        $pool->shutdown();
    });

    it('max executions works correctly with lazy spawning', function () {
        $pool = Parallel::pool(2)
            ->withLazySpawning()
            ->withMaxExecutionsPerWorker(3)
        ;

        $results = await(Promise::all([
            $pool->run(fn () => 'a'),
            $pool->run(fn () => 'b'),
            $pool->run(fn () => 'c'),
            $pool->run(fn () => 'd'),
            $pool->run(fn () => 'e'),
        ]));

        expect($results)->toHaveCount(5);

        $pool->shutdown();
    });

    it('withMaxExecutionsPerWorker() is immutable and does not affect original instance', function () {
        $base = Parallel::pool(2);
        $limited = $base->withMaxExecutionsPerWorker(5);

        $getMaxExecutions = function (object $pool): ?int {
            $ref = new \ReflectionObject($pool);
            $prop = $ref->getProperty('maxExecutionsPerWorker');

            return $prop->getValue($pool);
        };

        expect($base)->not->toBe($limited);
        expect($getMaxExecutions($base))->toBeNull();
        expect($getMaxExecutions($limited))->toBe(5);

        $base->shutdown();
        $limited->shutdown();
    });

    it('withMaxExecutionsPerWorker() can be chained with other configuration methods', function () {
        $pool = Parallel::pool(2)
            ->withMaxExecutionsPerWorker(10)
            ->withMemoryLimit('256M')
            ->withTimeout(30)
            ->withLazySpawning()
        ;

        $ref = new \ReflectionObject($pool);

        $maxExecProp = $ref->getProperty('maxExecutionsPerWorker');
        $memLimitProp = $ref->getProperty('memoryLimit');
        $spawnEagerProp = $ref->getProperty('spawnEagerly');

        expect($maxExecProp->getValue($pool))->toBe(10);
        expect($memLimitProp->getValue($pool))->toBe('256M');
        expect($spawnEagerProp->getValue($pool))->toBeFalse();

        $result = await($pool->run(fn () => 'chained'));
        expect($result)->toBe('chained');

        $pool->shutdown();
    });

    it('throws when max executions is less than 1', function () {
        expect(fn () => Parallel::pool(2)->withMaxExecutionsPerWorker(0))
            ->toThrow(\InvalidArgumentException::class, 'Max executions per worker must be at least 1')
        ;
    });

    it('throws when max executions is negative', function () {
        expect(fn () => Parallel::pool(2)->withMaxExecutionsPerWorker(-1))
            ->toThrow(\InvalidArgumentException::class, 'Max executions per worker must be at least 1')
        ;
    });

    it('getWorkerPids() reflects new worker PID after retirement', function () {
        $pool = Parallel::pool(1)->withMaxExecutionsPerWorker(2);

        await($pool->run(fn () => null));
        $pidsBeforeRetirement = $pool->getWorkerPids();

        await($pool->run(fn () => null));

        await($pool->run(fn () => null));

        await(delay(0.3));

        $pidsAfterRetirement = $pool->getWorkerPids();

        expect($pidsBeforeRetirement)->toHaveCount(1);
        expect($pidsAfterRetirement)->toHaveCount(1);

        expect($pidsAfterRetirement[0])->not->toBe($pidsBeforeRetirement[0]);

        $pool->shutdown();
    });

    it('getWorkerCount() stays stable through retirements', function () {
        $pool = Parallel::pool(3)->withMaxExecutionsPerWorker(2);

        await($pool->run(fn () => null));

        await(delay(0.3));

        expect($pool->getWorkerCount())->toBe(3);

        $results = await(Promise::all([
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
            $pool->run(fn () => null),
        ]));

        expect($results)->toHaveCount(6);

        await(delay(0.3));

        expect($pool->getWorkerCount())->toBe(3);

        $pool->shutdown();
    });
});
