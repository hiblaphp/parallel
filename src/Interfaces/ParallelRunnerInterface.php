<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for parallel runner implementations.
 *
 * This interface defines the contract for parallel runners that execute tasks
 * in parallel using worker processes.
 */
interface ParallelRunnerInterface
{
    /**
     * Submits a task to the pool for execution.
     *
     * The task will be added to a queue and executed by the next available worker.
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
     *  **NESTED EXECUTION & SAFETY:**
     * Hibla enforces a maximum nesting level (default 5) to prevent recursive
     * process spawning (Fork Bombs).
     *
     * IMPORTANT: To ensure that nesting limits are correctly enforced and that
     * exceptions are properly propagated back to the parent, you MUST await()
     * the result of a nested parallel() call. Un-awaited nested tasks may be
     * forcefully terminated if the parent process finishes its execution first.
     *
     * @template TResult
     * @param callable(): TResult $callback The closure or callable to execute.
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *        Fires before any pool-level handler registered via onMessage().
     *        Wrapped in async() — safe to use await() inside without blocking.
     * @return PromiseInterface<TResult> A promise that will be fulfilled with the return value of the task.
     */
    public function run(callable $callback, ?callable $onMessage = null): PromiseInterface;
}
