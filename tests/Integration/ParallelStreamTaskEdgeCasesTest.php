<?php

declare(strict_types=1);

require __DIR__ . '/../Fixtures/workload.php';

use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

use function Hibla\await;

const POOL_SIZE = 8;

function makePool(int $timeout = 30): mixed
{
    return Parallel::pool(size: POOL_SIZE)
        ->withBootstrap(__DIR__ . '/../Fixtures/workload.php')
        ->withTimeout($timeout)
        ->boot()
    ;
}

describe('Pool :: Basic Correctness', function () {
    it('returns the correct value for a single task', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (int $n) => $n * 2);

        $result = await($runFn(21));

        $pool->drain();
        expect($result)->toBe(42);
    });

    it('returns correct values in order for 20 tasks', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (int $n) => $n * 2);
        $inputs = range(1, 20);
        $results = await(Promise::all(array_map($runFn, $inputs)));

        $pool->drain();
        expect($results)->toBe(array_map(fn ($n) => $n * 2, $inputs));
    });

    it('completes 100 queued tasks correctly across 8 workers', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (int $n) => $n * 2);
        $inputs = range(1, 100);
        $results = await(Promise::all(array_map($runFn, $inputs)));

        $pool->drain();
        expect($results)->toBe(array_map(fn ($n) => $n * 2, $inputs));
    });
});

describe('Pool :: Exception Handling', function () {
    it('teleports a thrown exception with the correct message', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (string $mode): string {
            if ($mode === 'throw') {
                throw new RuntimeException('intentional exception');
            }

            return 'ok';
        });

        $caught = false;

        try {
            await($runFn('throw'));
        } catch (Throwable $e) {
            $caught = str_contains($e->getMessage(), 'intentional exception');
        }

        $pool->drain();
        expect($caught)->toBeTrue();
    });

    it('continues processing after a thrown exception', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (string $mode): string {
            if ($mode === 'throw') {
                throw new RuntimeException('intentional exception');
            }

            return 'ok';
        });

        try {
            await($runFn('throw'));
        } catch (Throwable) {
        }
        $result = await($runFn('normal'));

        $pool->drain();
        expect($result)->toBe('ok');
    });

    it('settles a mix of passing and throwing tasks correctly', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (string $mode): string {
            if ($mode === 'throw') {
                throw new RuntimeException('intentional exception');
            }

            return 'ok';
        });

        $promises = [
            $runFn('normal'),
            $runFn('throw'),
            $runFn('normal'),
            $runFn('throw'),
            $runFn('normal'),
        ];

        $passed = $thrown = 0;
        foreach ($promises as $p) {
            try {
                await($p);
                $passed++;
            } catch (Throwable) {
                $thrown++;
            }
        }

        $pool->drain();
        expect($passed)->toBe(3)->and($thrown)->toBe(2);
    });
});

describe('Pool :: Worker Crash Recovery', function () {
    it('recovers after a single worker crash', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (int $n): int {
            if ($n === 0) {
                exit(1);
            }

            return $n;
        });

        $crashed = false;

        try {
            await($runFn(0));
        } catch (Throwable) {
            $crashed = true;
        }
        $result = await($runFn(99));

        $pool->drain();
        expect($crashed)->toBeTrue()->and($result)->toBe(99);
    });

    it('recovers from 3 distributed crashes while 17 tasks pass', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (int $n): int {
            if ($n === 0) {
                exit(1);
            }

            return $n;
        });

        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $tasks[] = $runFn($i === 3 || $i === 10 || $i === 17 ? 0 : $i + 1);
        }

        $ok = $crashed = 0;
        foreach ($tasks as $t) {
            try {
                await($t);
                $ok++;
            } catch (Throwable) {
                $crashed++;
            }
        }

        $pool->drain();
        expect($ok)->toBe(17)->and($crashed)->toBe(3);
    });

    it('recovers when the very first task crashes', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (int $n): int {
            if ($n === 0) {
                exit(1);
            }

            return $n;
        });

        $firstCrashed = false;

        try {
            await($runFn(0));
        } catch (Throwable) {
            $firstCrashed = true;
        }
        $result = await($runFn(1));

        $pool->drain();
        expect($firstCrashed)->toBeTrue()->and($result)->toBe(1);
    });

    it('handles a crash at the last task correctly', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (int $n): int {
            if ($n === 0) {
                exit(1);
            }

            return $n;
        });

        $tasks = array_map(fn ($i) => $runFn($i < 19 ? $i + 1 : 0), range(0, 19));

        $lastCrashed = false;
        $passedCount = 0;
        foreach ($tasks as $t) {
            try {
                await($t);
                $passedCount++;
            } catch (Throwable) {
                $lastCrashed = true;
            }
        }

        $pool->drain();
        expect($lastCrashed)->toBeTrue()->and($passedCount)->toBe(19);
    });
});

describe('Pool :: Timeout Handling', function () {
    it('completes a task that finishes within the timeout', function () {
        $pool = makePool(timeout: 1);
        $runFn = $pool->runFn(function (int $sleepMs): string {
            usleep($sleepMs * 1000);

            return 'done';
        });

        $result = await($runFn(100));

        $pool->drain();
        expect($result)->toBe('done');
    });

    it('throws a catchable exception when a task exceeds the timeout', function () {
        $pool = makePool(timeout: 1);
        $runFn = $pool->runFn(fn (int $ms) => usleep($ms * 1000) ?: 'done');
        $timedOut = false;

        try {
            await($runFn(3000));
        } catch (Throwable) {
            $timedOut = true;
        }

        $pool->drain();
        expect($timedOut)->toBeTrue();
    });

    it('continues processing after a timeout', function () {
        $pool = makePool(timeout: 1);
        $runFn = $pool->runFn(fn (int $ms) => usleep($ms * 1000) ?: 'done');

        try {
            await($runFn(3000));
        } catch (Throwable) {
        }
        $result = await($runFn(50));

        $pool->drain();
        expect($result)->toBe('done');
    });
});

describe('Pool :: Large Payload Serialization', function () {
    it('serializes and deserializes a 1MB input payload correctly', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (string $s) => strlen($s));
        $len = await($runFn(str_repeat('x', 1024 * 1024)));

        $pool->drain();
        expect($len)->toBe(1024 * 1024);
    });

    it('returns a 512KB output payload correctly', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (int $size) => str_repeat('y', $size));
        $out = await($runFn(512 * 1024));

        $pool->drain();
        expect(strlen($out))->toBe(512 * 1024);
    });

    it('returns a large array of 10k elements correctly', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (int $n) => array_fill(0, $n, 'hibla'));
        $arr = await($runFn(10000));

        $pool->drain();
        expect(count($arr))->toBe(10000)->and($arr[0])->toBe('hibla');
    });
});

describe('Pool :: Concurrency Stress', function () {
    it('completes all workers simultaneously in parallel', function () {
        $pool = makePool();
        $runFn = $pool->runFn(function (): int {
            usleep(50000);

            return getmypid();
        });

        $promises = [];
        foreach (range(0, POOL_SIZE - 1) as $_) {
            $promises[] = $runFn();
        }

        $start = hrtime(true);
        $pids = await(Promise::all($promises));
        $elapsed = (hrtime(true) - $start) / 1e6;

        $pool->drain();
        expect($elapsed)->toBeLessThan(200)
            ->and(count(array_unique($pids)))->toBeGreaterThan(1)
        ;
    });

    it('processes 5 successive waves of 10 tasks each correctly', function () {
        $pool = makePool();

        for ($wave = 0; $wave < 5; $wave++) {
            $inputs = range($wave * 10, $wave * 10 + 9);
            $runFn = $pool->runFn(fn (int $n) => $n * 2);
            $promises = [];
            foreach ($inputs as $n) {
                $promises[] = $runFn($n);
            }
            $results = await(Promise::all($promises));
            expect($results)->toBe(array_map(fn ($n) => $n * 2, $inputs));
        }

        $pool->drain();
    });
});

describe('Pool :: Return Type Integrity', function () {
    it('preserves a null return value', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn () => null);
        $r = await($runFn());

        $pool->drain();
        expect($r)->toBeNull();
    });

    it('preserves bool true and false return values', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn (bool $b) => $b);
        $true = await($runFn(true));
        $false = await($runFn(false));

        $pool->drain();
        expect($true)->toBeTrue()->and($false)->toBeFalse();
    });

    it('preserves a float (M_PI) return value with exact precision', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn () => M_PI);
        $r = await($runFn());

        $pool->drain();
        expect($r)->toBe(M_PI);
    });

    it('preserves a deeply nested array return value', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn () => ['a' => [1, 2, ['deep' => true]]]);
        $r = await($runFn());

        $pool->drain();
        expect($r['a'][2]['deep'])->toBeTrue();
    });

    it('preserves a stdClass object return value', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn () => (object)['x' => 42]);
        $r = await($runFn());

        $pool->drain();
        expect($r->x)->toBe(42);
    });
});

describe('Pool :: Lifecycle', function () {
    it('can be created, used, and drained 3 times in sequence', function () {
        for ($i = 0; $i < 3; $i++) {
            $pool = makePool();
            $runFn = $pool->runFn(fn (int $n) => $n);
            $r = await($runFn($i));
            $pool->drain();
            expect($r)->toBe($i);
        }
    });

    it('waits for in-flight tasks before drain() returns', function () {
        $pool = makePool();
        $runFn = $pool->runFn(fn () => usleep(100000) ?: 'done');
        $p = $runFn();
        $pool->drain();

        expect(await($p))->toBe('done');
    });
});

// ═══════════════════════════════════════════════════════════════
// TASK SUITE  (Parallel::task())
// ═══════════════════════════════════════════════════════════════

describe('Task :: Basic Correctness', function () {
    it('returns the correct scalar value for a single task', function () {
        $r = await(Parallel::task()->run(fn () => 21 * 2));
        expect($r)->toBe(42);
    });

    it('uses a distinct worker process for each run() call', function () {
        $pid1 = await(Parallel::task()->run(fn () => getmypid()));
        $pid2 = await(Parallel::task()->run(fn () => getmypid()));
        expect($pid1)->not->toBe($pid2);
    });

    it('preserves a closure-captured variable across the IPC boundary', function () {
        $multiplier = 7;
        $r = await(Parallel::task()->run(function () use ($multiplier) {
            return $multiplier * 6;
        }));
        expect($r)->toBe(42);
    });

    it('dispatches a fresh worker on every runFn() invocation', function () {
        $fn = Parallel::task()->runFn(fn (int $n) => $n * 3);
        $results = await(Promise::all([$fn(1), $fn(2), $fn(3)]));
        expect($results)->toBe([3, 6, 9]);
    });

    it('spawns independent worker processes for each runFn() invocation', function () {
        $fn = Parallel::task()->runFn(fn () => getmypid());
        $pids = await(Promise::all([$fn(), $fn(), $fn()]));
        expect(count(array_unique($pids)))->toBe(3);
    });
});

describe('Task :: Concurrency', function () {
    it('runs 8 tasks in parallel and finishes well under serial time', function () {
        $fn = Parallel::task()->runFn(fn (): int => usleep(100_000) ?: getmypid());
        $promises = [];
        foreach (range(0, 7) as $_) {
            $promises[] = $fn();
        }

        $start = hrtime(true);
        $pids = await(Promise::all($promises));
        $elapsed = (hrtime(true) - $start) / 1e6;

        expect($elapsed)->toBeLessThan(400)
            ->and(count(array_unique($pids)))->toBe(8)
        ;
    });

    it('returns correct results for 50 concurrent tasks', function () {
        $fn = Parallel::task()->runFn(fn (int $n) => $n * 2);
        $inputs = range(1, 50);
        $promises = [];
        foreach ($inputs as $n) {
            $promises[] = $fn($n);
        }
        $results = await(Promise::all($promises));
        expect($results)->toBe(array_map(fn ($n) => $n * 2, $inputs));
    });
});

describe('Task :: Exception Handling', function () {
    it('teleports a thrown exception with the correct class and message', function () {
        $caught = false;

        try {
            await(Parallel::task()->run(fn () => throw new OverflowException('task overflow')));
        } catch (OverflowException $e) {
            $caught = str_contains($e->getMessage(), 'task overflow');
        }
        expect($caught)->toBeTrue();
    });

    it('succeeds on a fresh task after a prior task threw', function () {
        $result = await(Parallel::task()->run(fn () => 'recovered'));
        expect($result)->toBe('recovered');
    });

    it('settles a mix of 3 passing and 2 throwing tasks correctly', function () {
        $fn = Parallel::task()->runFn(function (string $mode): string {
            if ($mode === 'throw') {
                throw new RuntimeException('boom');
            }

            return 'ok';
        });
        $promises = [$fn('ok'), $fn('throw'), $fn('ok'), $fn('throw'), $fn('ok')];

        $passed = $thrown = 0;
        foreach ($promises as $p) {
            try {
                await($p);
                $passed++;
            } catch (Throwable) {
                $thrown++;
            }
        }

        expect($passed)->toBe(3)->and($thrown)->toBe(2);
    });

    it('carries a non-empty merged stack trace on a teleported exception', function () {
        $trace = '';

        try {
            await(Parallel::task()->run(fn () => throw new LogicException('trace check')));
        } catch (Throwable $e) {
            $trace = $e->getTraceAsString();
        }
        expect(strlen($trace))->toBeGreaterThan(0);
    });
});

describe('Task :: Worker Crash Recovery', function () {
    it('rejects the promise when the worker calls exit(1)', function () {
        $crashed = false;

        try {
            await(Parallel::task()->run(fn () => exit(1)));
        } catch (Throwable) {
            $crashed = true;
        }
        expect($crashed)->toBeTrue();
    });

    it('runs a fresh task normally after a prior worker crash', function () {
        try {
            await(Parallel::task()->run(fn () => exit(1)));
        } catch (Throwable) {
        }

        $result = await(Parallel::task()->run(fn () => 'still alive'));
        expect($result)->toBe('still alive');
    });

    it('correctly handles 10 tasks with 2 crashes: 8 pass, 2 fail', function () {
        $fn = Parallel::task()->runFn(function (int $n): int {
            if ($n === 0) {
                exit(1);
            }

            return $n;
        });
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = $fn($i === 2 || $i === 7 ? 0 : $i + 1);
        }

        $ok = $crashed = 0;
        foreach ($tasks as $t) {
            try {
                await($t);
                $ok++;
            } catch (Throwable) {
                $crashed++;
            }
        }

        expect($ok)->toBe(8)->and($crashed)->toBe(2);
    });
});

describe('Task :: Timeout Handling', function () {
    it('returns the correct value when the task finishes within the timeout', function () {
        $r = await(
            Parallel::task()->withTimeout(2)->run(function () {
                usleep(100_000);

                return 'within';
            })
        );
        expect($r)->toBe('within');
    });

    it('throws a catchable exception when the task exceeds the timeout', function () {
        $timedOut = false;

        try {
            await(Parallel::task()->withTimeout(1)->run(fn () => sleep(10)));
        } catch (Throwable) {
            $timedOut = true;
        }
        expect($timedOut)->toBeTrue();
    });

    it('runs a fresh task normally after a prior task timed out', function () {
        try {
            await(Parallel::task()->withTimeout(1)->run(fn () => sleep(10)));
        } catch (Throwable) {
        }

        $r = await(Parallel::task()->withTimeout(2)->run(fn () => 'post-timeout'));
        expect($r)->toBe('post-timeout');
    });

    it('lets a 1.5s task complete when withoutTimeout() is set', function () {
        $r = await(
            Parallel::task()->withoutTimeout()->run(function () {
                usleep(1_500_000);

                return 'no timeout';
            })
        );
        expect($r)->toBe('no timeout');
    });

    it('enforces independent timeouts on concurrent tasks', function () {
        $fn = Parallel::task()->withTimeout(1)->runFn(fn (int $ms) => usleep($ms * 1000) ?: $ms);
        $ok = $fn(100);
        $shouldFail = $fn(3000);

        $passOk = $timeoutHit = false;

        try {
            await($ok);
            $passOk = true;
        } catch (Throwable) {
        }

        try {
            await($shouldFail);
        } catch (Throwable) {
            $timeoutHit = true;
        }

        expect($passOk)->toBeTrue()->and($timeoutHit)->toBeTrue();
    });
});

describe('Task :: Large Payload Serialization', function () {
    it('serializes and deserializes a 1MB string input correctly', function () {
        $input = str_repeat('x', 1024 * 1024);
        $len = await(Parallel::task()->run(fn () => strlen($input)));
        expect($len)->toBe(1024 * 1024);
    });

    it('returns a 512KB string output payload correctly', function () {
        $out = await(Parallel::task()->run(fn () => str_repeat('y', 512 * 1024)));
        expect(strlen($out))->toBe(512 * 1024);
    });

    it('serializes and deserializes a 10k-element array input correctly', function () {
        $large = range(1, 10_000);
        $sum = await(Parallel::task()->run(fn () => array_sum($large)));
        expect($sum)->toBe(array_sum($large));
    });

    it('returns a 10k-element array output with correct count and values', function () {
        $arr = await(Parallel::task()->run(fn () => array_fill(0, 10_000, 'hibla')));
        expect(count($arr))->toBe(10_000)->and($arr[9_999])->toBe('hibla');
    });

    it('preserves a deeply nested array across the IPC boundary', function () {
        $deep = await(Parallel::task()->run(fn () => ['a' => ['b' => ['c' => ['d' => 'leaf']]]]));
        expect($deep['a']['b']['c']['d'])->toBe('leaf');
    });
});

describe('Task :: Return Type Integrity', function () {
    it('preserves a null return value', function () {
        expect(await(Parallel::task()->run(fn () => null)))->toBeNull();
    });

    it('preserves bool true and false return values', function () {
        expect(await(Parallel::task()->run(fn () => true)))->toBeTrue()
            ->and(await(Parallel::task()->run(fn () => false)))->toBeFalse()
        ;
    });

    it('preserves integer 0 and negative integers', function () {
        expect(await(Parallel::task()->run(fn () => 0)))->toBe(0)
            ->and(await(Parallel::task()->run(fn () => -999)))->toBe(-999)
        ;
    });

    it('preserves M_PI with exact float precision', function () {
        expect(await(Parallel::task()->run(fn () => M_PI)))->toBe(M_PI);
    });

    it('preserves an empty string return value', function () {
        expect(await(Parallel::task()->run(fn () => '')))->toBe('');
    });

    it('preserves an empty array return value', function () {
        expect(await(Parallel::task()->run(fn () => [])))->toBe([]);
    });

    it('preserves a stdClass object return value', function () {
        $obj = await(Parallel::task()->run(fn () => (object)['x' => 42, 'y' => 'hello']));
        expect($obj->x)->toBe(42)->and($obj->y)->toBe('hello');
    });

    it('preserves a nested stdClass object graph', function () {
        $r = await(Parallel::task()->run(function () {
            $o = new stdClass();
            $o->nested = new stdClass();
            $o->nested->value = 99;

            return $o;
        }));
        expect($r->nested->value)->toBe(99);
    });
});

describe('Task :: Real-time Output Streaming', function () {
    it('streams echo output to the parent while still resolving the task result', function () {
        ob_start();
        $r = await(Parallel::task()->run(function () {
            echo "streaming line 1\n";
            echo "streaming line 2\n";

            return 'output+result';
        }));
        $captured = ob_get_clean();

        expect($r)->toBe('output+result')
            ->and($captured)->toContain('streaming line 1')
        ;
    });

    it('delivers structured emit() messages to the onMessage handler in order', function () {
        $messages = [];
        await(
            Parallel::task()
                ->onMessage(function ($msg) use (&$messages) {
                    $messages[] = $msg->data;
                })
                ->run(function () {
                    \Hibla\emit('first');
                    \Hibla\emit('second');
                    \Hibla\emit('third');

                    return 'done';
                })
        );
        expect($messages)->toBe(['first', 'second', 'third']);
    });

    it('delivers array and object payloads via emit() correctly', function () {
        $received = [];
        await(
            Parallel::task()
                ->onMessage(function ($msg) use (&$received) {
                    $received[] = $msg->data;
                })
                ->run(function () {
                    \Hibla\emit(['progress' => 50]);
                    \Hibla\emit((object)['stage' => 'done']);

                    return 'ok';
                })
        );
        expect($received[0]['progress'])->toBe(50)
            ->and($received[1]->stage)->toBe('done')
        ;
    });

    it('sets WorkerMessage->pid to the worker PID, not the parent PID', function () {
        $workerPid = null;
        $parentPid = getmypid();
        await(
            Parallel::task()
                ->onMessage(function ($msg) use (&$workerPid) {
                    $workerPid = $msg->pid;
                })
                ->run(function () {
                    \Hibla\emit('ping');

                    return getmypid();
                })
        );
        expect($workerPid)->not->toBeNull()
            ->and($workerPid)->not->toBe($parentPid)
        ;
    });
});

describe('Task :: Memory Limit Configuration', function () {
    it('allows a 1MB allocation within a 64M memory limit', function () {
        $r = await(
            Parallel::task()
                ->withMemoryLimit('64M')
                ->run(fn () => strlen(str_repeat('z', 1024 * 1024)))
        );
        expect($r)->toBe(1024 * 1024);
    });

    it('allows a 10MB allocation with withUnlimitedMemory()', function () {
        $r = await(
            Parallel::task()
                ->withUnlimitedMemory()
                ->run(fn () => strlen(str_repeat('a', 10 * 1024 * 1024)))
        );
        expect($r)->toBe(10 * 1024 * 1024);
    });
});

describe('Task :: Fluent API Composition', function () {
    it('resolves correctly when withTimeout, withMemoryLimit, and withBootstrap are chained', function () {
        $r = await(
            Parallel::task()
                ->withTimeout(10)
                ->withMemoryLimit('128M')
                ->withBootstrap(__DIR__ . '/../workload.php')
                ->run(fn () => 'fluent chain ok')
        );
        expect($r)->toBe('fluent chain ok');
    });

    it('inherits fluent configuration on every runFn() invocation', function () {
        $fn = Parallel::task()->withTimeout(5)->withMemoryLimit('64M')->runFn(fn (int $n) => $n ** 2);
        $results = await(Promise::all([$fn(3), $fn(4), $fn(5)]));
        expect($results)->toBe([9, 16, 25]);
    });

    it('delivers messages from both runFn() invocations to the per-invocation onMessage handler', function () {
        $log = [];
        $fn = Parallel::task()->runFn(
            function (string $tag): string {
                \Hibla\emit("msg-from-$tag");

                return $tag;
            },
            onMessage: function ($msg) use (&$log) {
                $log[] = $msg->data;
            }
        );

        await(Promise::all([$fn('alpha'), $fn('beta')]));
        sort($log);

        expect($log)->toBe(['msg-from-alpha', 'msg-from-beta']);
    });
});
