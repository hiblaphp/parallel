<?php

declare(strict_types=1);

use function Hibla\await;
use function Hibla\parallel;

use Hibla\Parallel\Parallel;
use Tests\Fixtures\CustomTestException;

describe('Exception Propagation Integration', function () {

    describe('One-Off Parallel Tasks', function () {

        it('propagates a standard Exception with the correct message and code', function () {
            $task = function () {
                throw new Exception('A standard error occurred', 123);
            };

            try {
                await(parallel($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
                expect($e->getMessage())->toBe('A standard error occurred');
                expect($e->getCode())->toBe(123);
            }
        });

        it('propagates a specific built-in Exception type like InvalidArgumentException', function () {
            $task = function () {
                throw new InvalidArgumentException('Invalid argument provided');
            };

            try {
                await(parallel($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(InvalidArgumentException::class);
                expect($e->getMessage())->toBe('Invalid argument provided');
            }
        });

        it('propagates a custom user-defined Exception type', function () {
            $task = function () {
                throw new CustomTestException('A custom error occurred', 404);
            };

            try {
                await(parallel($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(CustomTestException::class);
                expect($e->getMessage())->toBe('A custom error occurred');
                expect($e->getCode())->toBe(404);
            }
        });

        it('rewrites the exception location to point to the `parallel` call site', function () {
            $exception = null;

            try {
                await(parallel(fn () => throw new Exception('Location test')));
            } catch (Exception $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(Exception::class);
            expect($exception->getFile())->toBe(__FILE__);
        });
    });

    describe('Persistent Pool Tasks', function () {

        $pool = null;

        beforeEach(function () use (&$pool) {
            $pool = Parallel::pool(1);
        });

        afterEach(function () use (&$pool) {
            if ($pool) {
                $pool->shutdown();
                $pool = null;
            }
        });

        it('propagates a standard Exception with the correct message and code', function () use (&$pool) {
            $task = function () {
                throw new Exception('A standard pool error occurred', 123);
            };

            try {
                await($pool->run($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(Exception::class);
                expect($e->getMessage())->toBe('A standard pool error occurred');
                expect($e->getCode())->toBe(123);
            }
        });

        it('propagates a specific built-in Exception type like InvalidArgumentException', function () use (&$pool) {
            $task = function () {
                throw new InvalidArgumentException('Invalid pool argument provided');
            };

            try {
                await($pool->run($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(InvalidArgumentException::class);
                expect($e->getMessage())->toBe('Invalid pool argument provided');
            }
        });

        it('propagates a custom user-defined Exception type', function () use (&$pool) {
            $task = function () {
                throw new CustomTestException('A custom pool error occurred', 404);
            };

            try {
                await($pool->run($task));
                $this->fail('Exception was not thrown');
            } catch (Exception $e) {
                expect($e)->toBeInstanceOf(CustomTestException::class);
                expect($e->getMessage())->toBe('A custom pool error occurred');
                expect($e->getCode())->toBe(404);
            }
        });

        it('rewrites the exception location to point to the pool `run` call site', function () use (&$pool) {
            $exception = null;

            try {
                await($pool->run(fn () => throw new Exception('Pool location test')));
            } catch (Exception $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(Exception::class);
            expect($exception->getFile())->toBe(__FILE__);
        });
    });
});
