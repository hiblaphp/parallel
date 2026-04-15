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
     * **NESTED CLOSURE WARNING** - AST CORRUPTION & FORK BOMBS
     *
     * Nesting `parallel()` or `spawn()` calls on the **same line** causes AST serialization
     * corruption in the underlying Opis Closure library. The reflection-based parser will
     * extract the outer closure instead of the inner one, causing the child worker to
     * mistakenly re-evaluate the parent call, triggering an infinite FORK BOMB.
     *
     * Furthermore, using short closures (`fn() => ...`) implicitly captures the entire
     * parent scope by value, which can serialize massive unintended payloads.
     *
     * The library AUTOMATICALLY DETECTS and THROWS EXCEPTIONS to prevent this,
     * but it's important to use the correct syntax.
     *
     * **DANGEROUS** (Single-line nested closures - will cause a fork bomb):
     * ```php
     * parallel(fn() => await(parallel(fn() => sleep(5))));
     * ```
     *
     * **RISKY** (Multi-line short closures - avoids fork bomb, but captures huge scope):
     * ```php
     * parallel(
     *     fn() => await(
     *         parallel(
     *             fn() => sleep(5)
     *         )
     *     )
     * );
     * ```
     *
     * **SAFER** (Regular closures - explicit scope prevents payload bloat):
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
     *
     * @param callable(): TResult $callback The closure or callable to execute.
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *                                                      Fires before any pool-level handler registered via onMessage().
     *                                                      Wrapped in async() — safe to use await() inside without blocking.
     *
     * @return PromiseInterface<TResult> A promise that will be fulfilled with the return value of the task.
     */
    public function run(callable $callback, ?callable $onMessage = null): PromiseInterface;

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
     * (See run() for nesting execution warnings and short closure rules).
     *
     * @template TResult
     *
     * @param callable(mixed ...): TResult $task The task to execute in parallel
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *
     * @return callable(mixed ...): PromiseInterface<TResult> A callable that returns a Promise
     */
    public function runFn(callable $task, ?callable $onMessage = null): callable;
}
