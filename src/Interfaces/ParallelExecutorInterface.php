<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for executing tasks in a new, isolated child process.
 *
 * Each call to `run()` or `spawn()` will create a fresh process that is
 * torn down after the task is complete.
 */
interface ParallelExecutorInterface extends ExecutorConfigInterface, MessagePassingInterface
{
    /**
     * Executes a task in a parallel process and returns a promise for its result.
     *
     * The parent process will wait for the returned promise to be resolved or
     * rejected. Output from the child process (echo, print) is streamed to the parent.
     *
     * **NESTED SHORT CLOSURE WARNING** - AST CORRUPTION & FORK BOMBS
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
     * **DANGEROUS** (Short closures - will corrupt serialization and fork bomb):
     * ```php
     * parallel(fn() => await(parallel(fn() => sleep(5))));
     * async(fn() => await(parallel(fn() => sleep(5))));
     * ```
     *
     * **SAFER** (Regular closures - explicit scope prevents serialization corruption):
     * ```php
     * parallel(function () {
     *     return await(parallel(function () {
     *         return sleep(5);
     *     }));
     * });
     * ```
     *
     * **SAFEST & RECOMMENDED** - Use non-closure callables for nested parallel tasks:
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
     * **IMPORTANT** - Functions and classes MUST be properly namespaced and autoloaded
     * via Composer to be available in child worker processes.
     *
     * @template TResult
     * @param callable(): TResult $callback The closure or callable to execute.
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *        Fires before any executor-level handler registered via onMessage().
     *        Wrapped in async() — safe to use await() inside without blocking.
     * @return PromiseInterface<TResult>
     */
    public function run(callable $callback, ?callable $onMessage = null): PromiseInterface;
}
