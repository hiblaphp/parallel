<?php

declare(strict_types=1);

namespace Tests\Integration;

use function Hibla\await;

use Hibla\Parallel\Parallel;
use Tests\Fixtures\CallableTestInstanceWorker;
use Tests\Fixtures\CallableTestInvokable;
use Tests\Fixtures\CallableTestInvokableWithArgs;
use Tests\Fixtures\CallableTestStaticWorker;

describe('CallbackExecutionTest', function () {

    describe('Parallel::task() accepts all callable types', function () {

        it('executes a regular closure', function () {
            $result = await(Parallel::task()->run(function () {
                return 'regular-closure';
            }));

            expect($result)->toBe('regular-closure');
        });

        it('executes a short closure', function () {
            $result = await(Parallel::task()->run(fn() => 'short-closure'));

            expect($result)->toBe('short-closure');
        });

        it('executes a static method array callable', function () {
            $result = await(Parallel::task()->run([CallableTestStaticWorker::class, 'run']));

            expect($result)->toBe('static-method');
        });

        it('executes an instance method array callable', function () {
            $worker = new CallableTestInstanceWorker('hello');
            $result = await(Parallel::task()->run([$worker, 'run']));

            expect($result)->toBe('instance-method-hello');
        });

        it('executes an invokable class', function () {
            $result = await(Parallel::task()->run(new CallableTestInvokable()));

            expect($result)->toBe('invokable-class');
        });

        it('executes a named function string', function () {
            $result = await(
                Parallel::task()->run('Tests\Fixtures\callable_test_named_function')
            );

            expect($result)->toBe('named-function');
        });
    });

    describe('Parallel::task() passes arguments for all callable types via runFn()', function () {

        it('passes args to a regular closure', function () {
            $task = Parallel::task()->runFn(function (string $prefix, int $value) {
                return "{$prefix}-{$value}";
            });

            $result = await($task('closure', 42));

            expect($result)->toBe('closure-42');
        });

        it('passes args to a static method array callable', function () {
            $task   = Parallel::task()->runFn([CallableTestStaticWorker::class, 'runWithArgs']);
            $result = await($task('static', 7));

            expect($result)->toBe('static-7');
        });

        it('passes args to an instance method array callable', function () {
            $worker = new CallableTestInstanceWorker('world');
            $task   = Parallel::task()->runFn([$worker, 'runWithArgs']);
            $result = await($task('inst', 3));

            expect($result)->toBe('inst-3-world');
        });

        it('passes args to an invokable class', function () {
            $task   = Parallel::task()->runFn(new CallableTestInvokableWithArgs());
            $result = await($task('inv', 99));

            expect($result)->toBe('inv-99');
        });

        it('passes args to a named function string', function () {
            $task = Parallel::task()->runFn(
                'Tests\Fixtures\callable_test_named_function_with_args'
            );

            $result = await($task('fn', 5));

            expect($result)->toBe('fn-5');
        });
    });

    describe('Parallel::pool() accepts all callable types', function () {

        it('executes a regular closure', function () {
            $pool   = Parallel::pool(size: 2);
            $result = await($pool->run(function () {
                return 'pool-regular-closure';
            }));

            expect($result)->toBe('pool-regular-closure');
            $pool->shutdown();
        });

        it('executes a short closure', function () {
            $pool   = Parallel::pool(size: 2);
            $result = await($pool->run(fn() => 'pool-short-closure'));

            expect($result)->toBe('pool-short-closure');
            $pool->shutdown();
        });

        it('executes a static method array callable', function () {
            $pool   = Parallel::pool(size: 2);
            $result = await($pool->run([CallableTestStaticWorker::class, 'run']));

            expect($result)->toBe('static-method');
            $pool->shutdown();
        });

        it('executes an instance method array callable', function () {
            $pool   = Parallel::pool(size: 2);
            $worker = new CallableTestInstanceWorker('pool');
            $result = await($pool->run([$worker, 'run']));

            expect($result)->toBe('instance-method-pool');
            $pool->shutdown();
        });

        it('executes an invokable class', function () {
            $pool   = Parallel::pool(size: 2);
            $result = await($pool->run(new CallableTestInvokable()));

            expect($result)->toBe('invokable-class');
            $pool->shutdown();
        });

        it('executes a named function string', function () {
            $pool   = Parallel::pool(size: 2);
            $result = await(
                $pool->run('Tests\Fixtures\callable_test_named_function')
            );

            expect($result)->toBe('named-function');
            $pool->shutdown();
        });
    });

    // ── Parallel::pool() — with arguments via runFn() ────────────────────────

    describe('Parallel::pool() passes arguments for all callable types via runFn()', function () {

        it('passes args to a regular closure', function () {
            $pool   = Parallel::pool(size: 2);
            $task   = $pool->runFn(function (string $prefix, int $value) {
                return "{$prefix}-{$value}";
            });
            $result = await($task('pool-closure', 1));

            expect($result)->toBe('pool-closure-1');
            $pool->shutdown();
        });

        it('passes args to a static method array callable', function () {
            $pool   = Parallel::pool(size: 2);
            $task   = $pool->runFn([CallableTestStaticWorker::class, 'runWithArgs']);
            $result = await($task('pool-static', 2));

            expect($result)->toBe('pool-static-2');
            $pool->shutdown();
        });

        it('passes args to an instance method array callable', function () {
            $pool   = Parallel::pool(size: 2);
            $worker = new CallableTestInstanceWorker('tagged');
            $task   = $pool->runFn([$worker, 'runWithArgs']);
            $result = await($task('pool-inst', 8));

            expect($result)->toBe('pool-inst-8-tagged');
            $pool->shutdown();
        });

        it('passes args to an invokable class', function () {
            $pool   = Parallel::pool(size: 2);
            $task   = $pool->runFn(new CallableTestInvokableWithArgs());
            $result = await($task('pool-inv', 10));

            expect($result)->toBe('pool-inv-10');
            $pool->shutdown();
        });

        it('passes args to a named function string', function () {
            $pool   = Parallel::pool(size: 2);
            $task   = $pool->runFn(
                'Tests\Fixtures\callable_test_named_function_with_args'
            );
            $result = await($task('pool-fn', 20));

            expect($result)->toBe('pool-fn-20');
            $pool->shutdown();
        });
    });

    describe('Parallel::task() accepts first-class callable syntax', function () {

        it('executes a named function via first-class callable', function () {
            $result = await(Parallel::task()->run(
                \Tests\Fixtures\callable_test_named_function(...)
            ));

            expect($result)->toBe('named-function');
        });

        it('executes a static method via first-class callable', function () {
            $result = await(Parallel::task()->run(CallableTestStaticWorker::run(...)));

            expect($result)->toBe('static-method');
        });

        it('executes an instance method via first-class callable', function () {
            $worker = new CallableTestInstanceWorker('fcc');
            $result = await(Parallel::task()->run($worker->run(...)));

            expect($result)->toBe('instance-method-fcc');
        });

        it('passes args to a named function via first-class callable', function () {
            $task   = Parallel::task()->runFn(\Tests\Fixtures\callable_test_named_function_with_args(...));
            $result = await($task('fcc', 1));

            expect($result)->toBe('fcc-1');
        });

        it('passes args to a static method via first-class callable', function () {
            $task   = Parallel::task()->runFn(CallableTestStaticWorker::runWithArgs(...));
            $result = await($task('fcc', 2));

            expect($result)->toBe('fcc-2');
        });

        it('passes args to an instance method via first-class callable', function () {
            $worker = new CallableTestInstanceWorker('fcc');
            $task   = Parallel::task()->runFn($worker->runWithArgs(...));
            $result = await($task('fcc', 3));

            expect($result)->toBe('fcc-3-fcc');
        });
    });

    describe('return values are correctly deserialized for all types', function () {

        it('returns a string', function () {
            $result = await(Parallel::task()->run(fn() => 'hello'));
            expect($result)->toBeString()->toBe('hello');
        });

        it('returns an integer', function () {
            $result = await(Parallel::task()->run(fn() => 42));
            expect($result)->toBeInt()->toBe(42);
        });

        it('returns a float', function () {
            $result = await(Parallel::task()->run(fn() => 3.14));
            expect($result)->toBeFloat()->toBe(3.14);
        });

        it('returns a boolean', function () {
            $result = await(Parallel::task()->run(fn() => true));
            expect($result)->toBeBool()->toBeTrue();
        });

        it('returns null', function () {
            $result = await(Parallel::task()->run(fn() => null));
            expect($result)->toBeNull();
        });

        it('returns an array', function () {
            $result = await(Parallel::task()->run(fn() => [1, 'two', 3.5]));
            expect($result)->toBe([1, 'two', 3.5]);
        });

        it('returns a stdClass object', function () {
            $result = await(Parallel::task()->run(function () {
                $obj       = new \stdClass();
                $obj->name = 'test';
                $obj->val  = 123;

                return $obj;
            }));

            expect($result)->toBeObject();
            expect($result->name)->toBe('test');
            expect($result->val)->toBe(123);
        });
    });
});
