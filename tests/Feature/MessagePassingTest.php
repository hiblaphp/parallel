<?php

declare(strict_types=1);

namespace Hibla\Parallel\Tests\Integration;

use Hibla\Parallel\Parallel;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Promise;
use Tests\Fixtures\ProgressUpdate;

use function Hibla\await;
use function Hibla\delay;
use function Hibla\emit;

describe('Message Passing Test', function () {
    test('one-off task receives messages via per-task handler', function () {
        $received = [];

        await(
            Parallel::task()->run(
                callback: function () {
                    emit('hello');
                    emit(['percent' => 50]);
                    emit(['percent' => 100]);

                    return 'done';
                },
                onMessage: function (WorkerMessage $msg) use (&$received) {
                    $received[] = $msg->data;
                }
            )
        );

        expect($received)->toHaveCount(3);
        expect($received[0])->toBe('hello');
        expect($received[1])->toBe(['percent' => 50]);
        expect($received[2])->toBe(['percent' => 100]);
    });

    test('one-off task per-task handler receives correct pid', function () {
        $receivedPid = null;

        await(
            Parallel::task()->run(
                callback: function () {
                    emit('ping');

                    return getmypid();
                },
                onMessage: function (WorkerMessage $msg) use (&$receivedPid) {
                    $receivedPid = $msg->pid;
                }
            )
        );

        expect($receivedPid)->toBeInt();
        expect($receivedPid)->toBeGreaterThan(0);
    });

    test('one-off task executor-level handler receives all messages', function () {
        $received = [];

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$received) {
                    $received[] = $msg->data;
                })
                ->run(function () {
                    emit(['step' => 1]);
                    emit(['step' => 2]);

                    return 'done';
                })
        );

        expect($received)->toHaveCount(2);
        expect($received[0])->toBe(['step' => 1]);
        expect($received[1])->toBe(['step' => 2]);
    });

    test('one-off task both executor-level and per-task handler fire for each message', function () {
        $executorReceived = [];
        $perTaskReceived = [];

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$executorReceived) {
                    $executorReceived[] = $msg->data;
                })
                ->run(
                    callback: function () {
                        emit(['step' => 1]);
                        emit(['step' => 2]);

                        return 'done';
                    },
                    onMessage: function (WorkerMessage $msg) use (&$perTaskReceived) {
                        $perTaskReceived[] = $msg->data;
                    }
                )
        );

        expect($perTaskReceived)->toHaveCount(2);
        expect($executorReceived)->toHaveCount(2);
        expect($perTaskReceived)->toBe($executorReceived);
    });

    test('one-off task correctly round-trips an emitted object', function () {
        $received = null;

        await(
            Parallel::task()->run(
                callback: function () {
                    emit(new ProgressUpdate(75, 'three-quarters'));

                    return 'done';
                },
                onMessage: function (WorkerMessage $msg) use (&$received) {
                    $received = $msg->data;
                }
            )
        );

        expect($received)->toBeInstanceOf(ProgressUpdate::class);
        expect($received->percent)->toBe(75);
        expect($received->label)->toBe('three-quarters');
    });

    test('one-off task emit() does not interfere with the return value', function () {
        $result = await(
            Parallel::task()->run(
                callback: function () {
                    emit('noise');
                    emit('more noise');

                    return ['key' => 'value'];
                },
                onMessage: fn (WorkerMessage $msg) => null
            )
        );

        expect($result)->toBe(['key' => 'value']);
    });

    test('one-off task with no handler silently discards MESSAGE frames', function () {
        $result = await(
            Parallel::task()->run(function () {
                emit('this should be silently discarded');

                return 'still works';
            })
        );

        expect($result)->toBe('still works');
    });

    test('pool per-task handler receives messages only from tests own task', function () {
        $pool = Parallel::pool(size: 2);

        $receivedA = [];
        $receivedB = [];

        await(Promise::all([
            $pool->run(
                callback: function () {
                    emit(['task' => 'A']);

                    return 'A';
                },
                onMessage: function (WorkerMessage $msg) use (&$receivedA) {
                    $receivedA[] = $msg->data;
                }
            ),
            $pool->run(
                callback: function () {
                    emit(['task' => 'B']);

                    return 'B';
                },
                onMessage: function (WorkerMessage $msg) use (&$receivedB) {
                    $receivedB[] = $msg->data;
                }
            ),
        ]));

        expect($receivedA)->toHaveCount(1);
        expect($receivedA[0])->toBe(['task' => 'A']);

        expect($receivedB)->toHaveCount(1);
        expect($receivedB[0])->toBe(['task' => 'B']);

        $pool->shutdown();
    });

    test('pool pool-level handler receives messages from all tasks', function () {
        $pool = Parallel::pool(size: 2);
        $allReceived = [];

        $pool = $pool->onMessage(
            function (WorkerMessage $msg) use (&$allReceived) {
                $allReceived[] = $msg->data;
            }
        );

        await(Promise::all([
            $pool->run(function () {
                emit(['task' => 'A']);

                return 'A';
            }),
            $pool->run(function () {
                emit(['task' => 'B']);

                return 'B';
            }),
        ]));

        expect($allReceived)->toHaveCount(2);

        $tasks = array_column($allReceived, 'task');
        sort($tasks);
        expect($tasks)->toBe(['A', 'B']);

        $pool->shutdown();
    });

    test('pool pool-level and per-task handlers both fire for each message', function () {
        $pool = Parallel::pool(size: 1);
        $poolReceived = [];
        $perTaskReceived = [];

        $pool = $pool->onMessage(
            function (WorkerMessage $msg) use (&$poolReceived) {
                $poolReceived[] = $msg->data;
            }
        );

        await(
            $pool->run(
                callback: function () {
                    emit(['step' => 1]);
                    emit(['step' => 2]);

                    return 'done';
                },
                onMessage: function (WorkerMessage $msg) use (&$perTaskReceived) {
                    $perTaskReceived[] = $msg->data;
                }
            )
        );

        expect($perTaskReceived)->toHaveCount(2);
        expect($poolReceived)->toHaveCount(2);
        expect($perTaskReceived)->toBe($poolReceived);

        $pool->shutdown();
    });

    test('pool correctly round-trips an emitted object', function () {
        $pool = Parallel::pool(size: 1);
        $received = null;

        await(
            $pool->run(
                callback: function () {
                    emit(new ProgressUpdate(25, 'quarter'));

                    return 'done';
                },
                onMessage: function (WorkerMessage $msg) use (&$received) {
                    $received = $msg->data;
                }
            )
        );

        expect($received)->toBeInstanceOf(ProgressUpdate::class);
        expect($received->percent)->toBe(25);
        expect($received->label)->toBe('quarter');

        $pool->shutdown();
    });

    test('pool emit() does not interfere with the return value', function () {
        $pool = Parallel::pool(size: 1);

        $result = await(
            $pool->run(
                callback: function () {
                    emit('noise');

                    return ['key' => 'value'];
                },
                onMessage: fn (WorkerMessage $msg) => null
            )
        );

        expect($result)->toBe(['key' => 'value']);
        $pool->shutdown();
    });

    test('pool with no handler silently discards MESSAGE frames', function () {
        $pool = Parallel::pool(size: 1);

        $result = await(
            $pool->run(function () {
                emit('this should be silently discarded');

                return 'still works';
            })
        );

        expect($result)->toBe('still works');
        $pool->shutdown();
    });

    test('emit() is a no-op in a background process', function () use (&$tempFiles) {
        $proofFile = sys_get_temp_dir() . '/emit_noop_proof_' . uniqid() . '.txt';
        $tempFiles[] = $proofFile;

        $process = await(
            Parallel::background()->spawn(function () use ($proofFile) {
                emit(['this' => 'should not appear']);
                file_put_contents($proofFile, 'completed');
            })
        );

        $attempts = 0;
        while (! file_exists($proofFile) && $attempts < 20) {
            usleep(100000);
            $attempts++;
        }

        expect(file_exists($proofFile))->toBeTrue();
        expect(file_get_contents($proofFile))->toBe('completed');

        $process->terminate();
    });

    test('background executor does not expose onMessage method', function () {
        expect(
            method_exists(Parallel::background(), 'onMessage')
        )->toBeFalse();
    });

    test('multiple onMessage handlers fire in registration order', function () {
        $order = [];

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$order) {
                    $order[] = 'first';
                })
                ->onMessage(function (WorkerMessage $msg) use (&$order) {
                    $order[] = 'second';
                })
                ->onMessage(function (WorkerMessage $msg) use (&$order) {
                    $order[] = 'third';
                })
                ->run(function () {
                    emit('ping');

                    return 'done';
                })
        );

        expect($order)->toBe(['first', 'second', 'third']);
    });

    test('multiple onMessage handlers all receive every message', function () {
        $receivedA = [];
        $receivedB = [];

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$receivedA) {
                    $receivedA[] = $msg->data;
                })
                ->onMessage(function (WorkerMessage $msg) use (&$receivedB) {
                    $receivedB[] = $msg->data;
                })
                ->run(function () {
                    emit(['step' => 1]);
                    emit(['step' => 2]);
                    emit(['step' => 3]);

                    return 'done';
                })
        );

        expect($receivedA)->toHaveCount(3);
        expect($receivedB)->toHaveCount(3);
        expect($receivedA)->toBe($receivedB);
    });

    test('pool multiple onMessage handlers fire in registration order', function () {
        $order = [];

        $pool = Parallel::pool(size: 1)
            ->onMessage(function (WorkerMessage $msg) use (&$order) {
                $order[] = 'first';
            })
            ->onMessage(function (WorkerMessage $msg) use (&$order) {
                $order[] = 'second';
            })
            ->onMessage(function (WorkerMessage $msg) use (&$order) {
                $order[] = 'third';
            })
        ;

        await($pool->run(function () {
            emit('ping');

            return 'done';
        }));

        expect($order)->toBe(['first', 'second', 'third']);
        $pool->shutdown();
    });

    test('pool-level handlers fire before per-task handler', function () {
        $order = [];

        $pool = Parallel::pool(size: 1)
            ->onMessage(function (WorkerMessage $msg) use (&$order) {
                $order[] = 'pool-first';
            })
            ->onMessage(function (WorkerMessage $msg) use (&$order) {
                $order[] = 'pool-second';
            })
        ;

        await($pool->run(
            callback: function () {
                emit('ping');

                return 'done';
            },
            onMessage: function (WorkerMessage $msg) use (&$order) {
                $order[] = 'per-task';
            }
        ));

        expect($order)->toBe(['pool-first', 'pool-second', 'per-task']);
        $pool->shutdown();
    });

    test('executor-level handlers fire before per-task handler', function () {
        $order = [];

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$order) {
                    $order[] = 'executor-first';
                })
                ->onMessage(function (WorkerMessage $msg) use (&$order) {
                    $order[] = 'executor-second';
                })
                ->run(
                    callback: function () {
                        emit('ping');

                        return 'done';
                    },
                    onMessage: function (WorkerMessage $msg) use (&$order) {
                        $order[] = 'per-task';
                    }
                )
        );

        expect($order)->toBe(['executor-first', 'executor-second', 'per-task']);
    });

    test('multiple async handlers execute concurrently not sequentially', function () {
        $startTime = microtime(true);

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) {
                    await(delay(1));
                })
                ->onMessage(function (WorkerMessage $msg) {
                    await(delay(1));
                })
                ->onMessage(function (WorkerMessage $msg) {
                    await(delay(1));
                })
                ->run(function () {
                    emit('trigger');

                    return 'done';
                })
        );

        $elapsed = microtime(true) - $startTime;

        expect($elapsed)->toBeLessThan(2.0);
    });

    test('pool multiple async handlers execute concurrently not sequentially', function () {
        $pool = Parallel::pool(size: 1)
            ->onMessage(function (WorkerMessage $msg) {
                await(delay(1));
            })
            ->onMessage(function (WorkerMessage $msg) {
                await(delay(1));
            })
        ;

        $startTime = microtime(true);

        await($pool->run(
            callback: function () {
                emit('trigger');

                return 'done';
            },
            onMessage: function (WorkerMessage $msg) {
                await(delay(1));
            }
        ));

        $elapsed = microtime(true) - $startTime;

        expect($elapsed)->toBeLessThan(2.0);

        $pool->shutdown();
    });

    test('task promise does not resolve before all async handlers complete', function () {
        $handlerCompleted = false;

        await(
            Parallel::task()
                ->onMessage(function (WorkerMessage $msg) use (&$handlerCompleted) {
                    await(delay(0.5));
                    $handlerCompleted = true;
                })
                ->run(function () {
                    emit('trigger');

                    return 'done';
                })
        );

        expect($handlerCompleted)->toBeTrue();
    });

    test('pool task promise does not resolve before all async handlers complete', function () {
        $pool = Parallel::pool(size: 1);
        $handlerCompleted = false;

        $pool = $pool->onMessage(function (WorkerMessage $msg) use (&$handlerCompleted) {
            await(delay(0.5));
            $handlerCompleted = true;
        });

        await($pool->run(function () {
            emit('trigger');

            return 'done';
        }));

        expect($handlerCompleted)->toBeTrue();
        $pool->shutdown();
    });

    test('onMessage chaining is immutable and does not affect original instance', function () {
        $base = Parallel::task();
        $withOne = $base->onMessage(fn (WorkerMessage $msg) => null);
        $withTwo = $withOne->onMessage(fn (WorkerMessage $msg) => null);

        $getHandlerCount = function (object $executor): int {
            $ref = new \ReflectionObject($executor);
            $prop = $ref->getProperty('onMessageHandlers');

            return \count($prop->getValue($executor));
        };

        expect($getHandlerCount($base))->toBe(0);
        expect($getHandlerCount($withOne))->toBe(1);
        expect($getHandlerCount($withTwo))->toBe(2);
    });

    test('pool onMessage chaining is immutable and does not affect original instance', function () {
        $base = Parallel::pool(size: 1);
        $withOne = $base->onMessage(fn (WorkerMessage $msg) => null);
        $withTwo = $withOne->onMessage(fn (WorkerMessage $msg) => null);

        $getHandlerCount = function (object $pool): int {
            $ref = new \ReflectionObject($pool);
            $prop = $ref->getProperty('onMessageHandlers');

            return \count($prop->getValue($pool));
        };

        expect($getHandlerCount($base))->toBe(0);
        expect($getHandlerCount($withOne))->toBe(1);
        expect($getHandlerCount($withTwo))->toBe(2);

        $base->shutdown();
        $withOne->shutdown();
        $withTwo->shutdown();
    });
});
