<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Parallel\Internals\WorkerContext;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Run a task in parallel (separate process) and return a Promise.
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
 * parallel(fn() => await(parallel(fn() => sleep(5))));
 * async(fn() => await(parallel(fn() => sleep(5))));
 *
 * **SAFER** (Regular closures - explicit scope prevents serialization corruption):
 * parallel(function () {
 *     return await(parallel(function () {
 *         return sleep(5);
 *     }));
 * });
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
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int|null $timeout Maximum seconds to wait for task completion (default: from config or 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 */
function parallel(callable $task, ?int $timeout = null): PromiseInterface
{
    $executor = Parallel::task();

    if ($timeout !== null) {
        $executor = $executor->withTimeout($timeout);
    }

    return $executor->run($task);
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
 * **NESTED EXECUTION & SAFETY:**
 * Hibla enforces a maximum nesting level (default 5) to prevent recursive
 * process spawning (Fork Bombs).
 *
 * IMPORTANT: To ensure that nesting limits are correctly enforced and that
 * exceptions are properly propagated back to the parent, you MUST await()
 * the result of a nested parallel() call. Un-awaited nested tasks may be
 * forcefully terminated if the parent process finishes its execution first.
 *
 * **IMPORTANT** - The wrapped callable must be properly namespaced and autoloaded
 * via Composer to be available in child worker processes.
 *
 * @template TResult
 *
 * @param callable(mixed...): TResult $task The task to execute in parallel
 * @param int|null $timeout Maximum seconds to wait (default: from config or 60)
 *
 * @return callable(mixed...): PromiseInterface<TResult> A callable that returns a Promise
 */
function parallelFn(callable $task, ?int $timeout = null): callable
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
 * **NESTED SHORT CLOSURE WARNING** - AST CORRUPTION & FORK BOMBS
 *
 * Nesting `spawn()` calls using arrow functions / short closures (`fn() => ...`)
 * causes AST serialization corruption and infinite fork bombs. See `parallel()`
 * documentation for details.
 *
 *  **DANGEROUS** (Short closures):
 * spawn(fn() => await(spawn(fn() => sleep(5))));
 *
 * **SAFER** (Regular closures):
 * spawn(function() {
 *     return await(spawn(function() { sleep(5); }));
 * });
 *
 *  **SAFEST** (Non-closure callables):
 * - Array callables:[MyClass::class, 'method']
 * - Invokable classes: new MyInvokableTask()
 * - String functions: 'App\Tasks\myFunction'
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
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int|null $timeout Maximum seconds for task completion (default: from config or 600)
 * @return PromiseInterface<BackgroundProcess> Promise resolving to the Process instance
 */
function spawn(callable $task, ?int $timeout = null): PromiseInterface
{
    $executor = Parallel::background();

    if ($timeout !== null) {
        $executor = $executor->withTimeout($timeout);
    }

    return $executor->spawn($task);
}

/**
 * Wrap a callable to return a new callable that spawns a background process when invoked.
 *
 * This acts as a factory for fire-and-forget background tasks.
 *
 * **IMPORTANT** - The wrapped callable must be properly namespaced and autoloaded
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
 * @template TResult
 *
 * @param callable(mixed ...$args): TResult $task The task to execute in background
 * @param int|null $timeout Maximum seconds for the background process (default: from config or 600)
 *
 * @return callable(): PromiseInterface<BackgroundProcess> A callable that returns a Promise resolving to the Process
 */
function spawnFn(callable $task, ?int $timeout = null): callable
{
    return static function (mixed ...$args) use ($task, $timeout): PromiseInterface {
        return spawn(static fn () => $task(...$args), $timeout);
    };
}

/**
 * Emit a message from a worker process to the parent.
 *
 * Sends a structured MESSAGE frame to the parent process via stdout,
 * bypassing the output buffer so it is never captured as task output.
 *
 * Supports any serializable PHP value — scalars, arrays, and objects
 * all round-trip correctly across the process boundary. Objects are
 * transparently serialized using base64(serialize()) and reconstructed
 * into their original type on the parent side when building WorkerMessage.
 *
 * Silently no-ops when called outside a worker context (e.g., in the
 * parent process or in a fire-and-forget background worker where stdout
 * is /dev/null and message passing is unavailable).
 *
 * @param mixed $data The data to send to the parent process
 * @return void
 */
function emit(mixed $data): void
{
    // Silently no-op outside a streamed worker context — safe to call
    // from shared code that runs in both parent and worker processes
    if (! WorkerContext::isWorker()) {
        return;
    }

    $needsSerialization = \is_object($data) || \is_resource($data);

    $frame = [
        'status' => 'MESSAGE',
        'pid' => getmypid(),
        'data' => $needsSerialization ? base64_encode(serialize($data)) : $data,
        'data_serialized' => $needsSerialization,
    ];

    // Include task_id when running inside a persistent worker so the
    // parent can route the message to the correct per-task handler
    $taskId = WorkerContext::getCurrentTaskId();
    if ($taskId !== null) {
        $frame['task_id'] = $taskId;
    }

    $json = json_encode($frame, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    // Write directly to STDOUT bypassing ob_start — the output buffer
    // is reserved for echo/print output forwarded as OUTPUT frames.
    // Mixing MESSAGE frames into the buffer would corrupt the stream.
    fwrite(STDOUT, $json . PHP_EOL);
    fflush(STDOUT);
}
