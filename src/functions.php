<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Run a task in parallel (separate process) and return a Promise.
 *
 * ⚠️ NESTED SHORT CLOSURE WARNING - AST CORRUPTION & FORK BOMBS
 *
 * Nesting `parallel()` calls using arrow functions / short closures (`fn() => ...`)
 * causes AST serialization corruption in the underlying Opis Closure library.
 * Because short closures automatically capture the entire parent scope by value,
 * nesting them corrupts the serialized payload. This causes the child worker to
 * mistakenly re-evaluate the parent call, triggering an infinite FORK BOMB.
 *
 * The library AUTOMATICALLY DETECTS and THROWS EXCEPTIONS to prevent this,
 * but it's important to use the correct syntax.
 *
 * ❌ DANGEROUS (Short closures - will corrupt serialization and fork bomb):
 * parallel(fn() => await(parallel(fn() => sleep(5))));
 * async(fn() => await(parallel(fn() => sleep(5))));
 *
 * ✅ SAFER (Regular closures - explicit scope prevents serialization corruption):
 * parallel(function () {
 *     return await(parallel(function () {
 *         return sleep(5);
 *     }));
 * });
 *
 * ✅ SAFEST & RECOMMENDED - Use non-closure callables for nested parallel tasks:
 *
 * 1. Array callable (static method):
 *    parallel([MyTask::class, 'run']);
 *
 * 2. Array callable (instance method):
 *    parallel([new MyTask(), 'execute']);
 *
 * 3. Invokable class:
 *    parallel(new MyInvokableTask());
 *
 * 4. String function name:
 *    parallel('myNamespacedFunction');
 *
 * ⚠️ IMPORTANT: Functions and classes MUST be properly namespaced and autoloaded
 * via Composer to be available in child worker processes.
 *
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds to wait for task completion (default: 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 */
function parallel(callable $task, int $timeout = 60): PromiseInterface
{
    return Parallel::task()
        ->withTimeout($timeout)
        ->run($task)
    ;
}

/**
 * Wrap a callable to return a new callable that executes in parallel when invoked.
 *
 * This acts as a factory for parallel tasks. It is particularly useful when working
 * with higher-order functions like `Promise::map()`, `array_map()`, or event listeners
 * where you need to pass a callable that triggers a parallel process.
 *
 * The arguments passed to the returned function will be serialized and passed
 * to the background process.
 *
 * ⚠️ IMPORTANT: The wrapped callable must be properly namespaced and autoloaded
 * via Composer to be available in child worker processes.
 *
 * @template TResult
 *
 * @param callable(mixed ...$args): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds to wait (default: 60)
 *
 * @return callable(mixed ...$args): PromiseInterface<TResult> A callable that returns a Promise
 */
function parallelFn(callable $task, int $timeout = 60): callable
{
    return static function (mixed ...$args) use ($task, $timeout): PromiseInterface {
        return parallel(static fn () => $task(...$args), $timeout);
    };
}

/**
 * Spawn a background process and return the Process instance for manual control.
 *
 * Unlike `parallel()`, this gives you full control over the process lifecycle.
 * You must manually call `await()` on the returned process to get the result.
 *
 * ⚠️ NESTED SHORT CLOSURE WARNING - AST CORRUPTION & FORK BOMBS
 *
 * Nesting `spawn()` calls using arrow functions / short closures (`fn() => ...`)
 * causes AST serialization corruption and infinite fork bombs. See `parallel()`
 * documentation for details.
 *
 * ❌ DANGEROUS (Short closures):
 * spawn(fn() => await(spawn(fn() => sleep(5))));
 *
 * ✅ SAFER (Regular closures):
 * spawn(function() {
 *     return await(spawn(function() { sleep(5); }));
 * });
 *
 * ✅ SAFEST (Non-closure callables):
 * - Array callables: [MyClass::class, 'method']
 * - Invokable classes: new MyInvokableTask()
 * - String functions: 'App\Tasks\myFunction'
 *
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds for task completion (default: 600)
 * @return PromiseInterface<BackgroundProcess> Promise resolving to the Process instance
 */
function spawn(callable $task, int $timeout = 600): PromiseInterface
{
    return Parallel::background()
        ->withTimeout($timeout)
        ->spawn($task)
    ;
}

/**
 * Wrap a callable to return a new callable that spawns a background process when invoked.
 *
 * This acts as a factory for fire-and-forget background tasks.
 *
 * ⚠️ IMPORTANT: The wrapped callable must be properly namespaced and autoloaded
 * via Composer to be available in child worker processes.
 *
 * @template TResult
 *
 * @param callable(mixed ...$args): TResult $task The task to execute in background
 * @param int $timeout Maximum seconds for the background process (default: 600)
 *
 * @return callable(): PromiseInterface<BackgroundProcess> A callable that returns a Promise resolving to the Process
 */
function spawnFn(callable $task, int $timeout = 600): callable
{
    return static function (mixed ...$args) use ($task, $timeout): PromiseInterface {
        return spawn(static fn () => $task(...$args), $timeout);
    };
}
