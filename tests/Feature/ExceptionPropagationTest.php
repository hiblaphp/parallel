<?php

use Tests\Fixtures\CustomTestException;

use function Hibla\parallel;
use function Hibla\await;

describe('Exception Propagation Integration', function () {
    it('propagates a standard Exception with the correct message and code', function () {
        $task = function () {
            throw new \Exception("A standard error occurred", 123);
        };

        expect(fn() => await(parallel($task)))
            ->toThrow(\Exception::class, "A standard error occurred")
            ->and(fn(\Exception $e) => expect($e->getCode())->toBe(123));
    });

    it('propagates a specific built-in Exception type like InvalidArgumentException', function () {
        $task = function () {
            throw new \InvalidArgumentException("Invalid argument provided");
        };

        expect(fn() => await(parallel($task)))
            ->toThrow(\InvalidArgumentException::class, "Invalid argument provided");
    });

    it('propagates a custom user-defined Exception type', function () {
        $task = function () {
            throw new CustomTestException("A custom error occurred", 404);
        };

        expect(fn() => await(parallel($task)))
            ->toThrow(CustomTestException::class, "A custom error occurred")
            ->and(fn(CustomTestException $e) => expect($e->getCode())->toBe(404));
    });

    it('rewrites the exception location to point to the `parallel` call site', function () {
        $exception = null;

        try {
            await(parallel(fn() => throw new \Exception("Location test")));
        } catch (\Exception $e) {
            $exception = $e;
        }


        expect($exception)->toBeInstanceOf(\Exception::class);
        expect($exception->getFile())->toBe(__FILE__);
    });
});