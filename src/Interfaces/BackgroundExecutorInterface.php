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
}
