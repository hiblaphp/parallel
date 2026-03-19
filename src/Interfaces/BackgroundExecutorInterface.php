<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for spawning fire-and-forget background processes.
 */
interface BackgroundExecutorInterface extends ExecutorConfigInterface
{
    /**
     * Spawns a "fire-and-forget" background task.
     *
     * This creates a detached process that runs independently. The returned promise
     * resolves immediately with a `BackgroundProcess` instance, which can be used
     * to check the status of or terminate the process.
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
     * **NESTED EXECUTION & SAFETY:**
     * Hibla enforces a maximum nesting level (default 5) to prevent recursive
     * process spawning (Fork Bombs).
     *
     * IMPORTANT: To ensure that nesting limits are correctly enforced and that
     * exceptions are properly propagated back to the parent, you MUST await()
     * the result of a nested parallel() call. Un-awaited nested tasks may be
     * forcefully terminated if the parent process finishes its execution first.
     *
     * @param callable(): mixed $callback The closure or callable to execute.
     * @return PromiseInterface<BackgroundProcess> A promise that resolves with the process handle.
     */
    public function spawn(callable $callback): PromiseInterface;

    /**
     * Wrap a callable to return a new callable that spawns a background process when invoked.
     *
     * This acts as a factory for fire-and-forget background tasks.
     *
     * The arguments passed to the returned function will be serialized and passed
     * to the background process.
     *
     * (See spawn() for nesting execution warnings and short closure rules).
     *
     * @param callable(mixed ...$args): mixed $task The task to execute in background
     * @return callable(mixed ...$args): PromiseInterface<BackgroundProcess> A callable that returns a Promise
     */
    public function spawnFn(callable $task): callable;
}
