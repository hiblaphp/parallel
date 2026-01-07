<?php

declare(strict_types=1);

namespace Hibla;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\Serializer\Exceptions\SerializationException;

/**
 * Run a task in parallel (separate process) and return a Promise.
 *
 * ⚠️ NESTED PARALLEL CLOSURE WARNING - FORK BOMB PREVENTION
 *
 * Nesting parallel() calls with closures can cause FORK BOMBS where each process
 * spawns exponentially more processes, rapidly exhausting system resources.
 *
 * The library AUTOMATICALLY DETECTS and THROWS EXCEPTIONS to prevent fork bombs,
 * but it's better to avoid this pattern entirely.
 *
 * ❌ DANGEROUS (closure nesting - library will throw exception to prevent fork bomb):
 * parallel(fn() => await(parallel(fn() => sleep(5))));
 * async(fn() => await(parallel(fn() => sleep(5))));
 *
 * ✅ RECOMMENDED - Use non-closure callables for nested parallel tasks:
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
 * ⚠️ IMPORTANT: Functions and classes MUST be:
 * - Properly namespaced
 * - Autoloaded via Composer
 * - Available in child worker processes
 *
 * This ensures context is preserved when the task executes in the child process.
 *
 * ✅ SAFE PATTERNS:
 *
 * // Using static methods
 * class DataProcessor {
 *     public static function process($data) {
 *         return await(parallel([self::class, 'heavyComputation']));
 *     }
 *     
 *     public static function heavyComputation() {
 *         return expensive_operation();
 *     }
 * }
 * await(parallel([DataProcessor::class, 'process']));
 *
 * // Using invokable classes
 * class InnerTask {
 *     public function __invoke() {
 *         return expensive_operation();
 *     }
 * }
 *
 * class OuterTask {
 *     public function __invoke() {
 *         return await(parallel(new InnerTask()));
 *     }
 * }
 * await(parallel(new OuterTask()));
 *
 * // Using namespaced functions (must be autoloaded)
 * namespace App\Tasks;
 * function innerTask() { return expensive_operation(); }
 * function outerTask() { return await(parallel('App\Tasks\innerTask')); }
 * await(parallel('App\Tasks\outerTask'));
 *
 * // Multi-line closure formatting (safer but still not recommended for nesting)
 * async(
 *     fn() => await(parallel(
 *         fn() => sleep(5)
 *     ))
 * );
 *
 * ✅ BEST PRACTICE - Avoid nesting entirely when possible:
 * await(parallel(fn() => sleep(5)));
 *
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds to wait for task completion (default: 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 *
 * @throws \RuntimeException If fork bomb pattern is detected
 * @throws SerializationException If task cannot be serialized
 *
 * @example
 * ```php
 * // Simple parallel execution with closure
 * $result = await(parallel(fn() => heavyComputation()));
 *
 * // Nested parallel with array callable (RECOMMENDED)
 * class Task {
 *     public static function run() {
 *         return await(parallel([self::class, 'process']));
 *     }
 *     public static function process() {
 *         return expensiveWork();
 *     }
 * }
 * $result = await(parallel([Task::class, 'run']));
 *
 * // With timeout
 * $result = await(parallel(fn() => slowTask(), 120));
 * ```
 */
function parallel(callable $task, int $timeout = 60): PromiseInterface
{
    $source = new CancellationTokenSource();

    /** @var Process<TResult> $process */
    $process = ProcessManager::getGlobal()->spawnStreamedTask($task, $timeout);

    $source->token->onCancel(function () use ($process) {
        $process->terminate();
    });

    /** @var PromiseInterface<TResult> */
    return $process->getResult($timeout)
        ->onCancel(function () use ($source) {
            $source->cancel();
        })
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
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds to wait (default: 60)
 *
 * @return callable(): PromiseInterface<TResult> A callable that returns a Promise
 *
 * @example
 * ```php
 * // Create a reusable parallel function
 * $resizeImage = parallelFn(function(string $path) {
 *     // This runs in a separate process
 *     return Image::load($path)->resize(100, 100)->save();
 * });
 *
 * $images = ['img1.jpg', 'img2.jpg', 'img3.jpg'];
 *
 * // Use with Promise::map to process concurrently
 * $results = await(Promise::map($images, $resizeImage, concurrency: 2));
 * ```
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
 * ⚠️ NESTED SPAWN CLOSURE WARNING - FORK BOMB PREVENTION
 *
 * Nesting spawn() calls with closures can cause FORK BOMBS. The library automatically
 * detects and throws exceptions to prevent this, but you should use non-closure
 * callables for nested parallel operations.
 *
 * ❌ DANGEROUS (closure nesting - library will throw exception):
 * spawn(fn() => await(spawn(fn() => sleep(5))));
 * async(fn() => await(spawn(fn() => sleep(5))));
 *
 * ✅ RECOMMENDED - Use non-closure callables:
 * - Array callables: [MyClass::class, 'method']
 * - Invokable classes: new MyInvokableTask()
 * - String functions: 'App\Tasks\myFunction'
 *
 * ⚠️ IMPORTANT: All callables must be:
 * - Properly namespaced
 * - Autoloaded via Composer
 * - Available in child worker processes
 *
 * ✅ SAFE (multi-line formatting):
 * async(
 *     fn() => await(spawn(
 *         fn() => sleep(5)
 *     ))
 * );
 *
 * ✅ BEST (avoid nesting entirely):
 * await(spawn(fn() => sleep(5)));
 *
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds for task completion (default: 600)
 * @return PromiseInterface<BackgroundProcess> Promise resolving to the Process instance
 *
 * @throws \RuntimeException If process spawning fails or fork bomb pattern detected
 * @throws SerializationException If task cannot be serialized
 *
 * @example
 * ```php
 * // Spawn multiple processes
 * $process1 = await(spawn(fn() => task1()));
 * $process2 = await(spawn(fn() => task2()));
 * $process3 = await(spawn(fn() => task3()));
 *
 * // Wait for all
 * $result1 = await($process1->await());
 * $result2 = await($process2->await());
 * $result3 = await($process3->await());
 *
 * // Nested spawn with array callable (RECOMMENDED)
 * class BackgroundJob {
 *     public static function run() {
 *         $child = await(spawn([self::class, 'childTask']));
 *         return await($child->await());
 *     }
 *     public static function childTask() {
 *         return longRunningWork();
 *     }
 * }
 * $process = await(spawn([BackgroundJob::class, 'run']));
 * $result = await($process->await());
 *
 * // Cancel if needed
 * $process = await(spawn(fn() => longRunningTask()));
 * if ($shouldCancel) {
 *     $process->cancel();
 * }
 *
 * // Check status
 * $process = await(spawn(fn() => backgroundJob()));
 * echo "PID: " . $process->getPid() . "\n";
 * echo "Running: " . ($process->isRunning() ? 'yes' : 'no') . "\n";
 * ```
 */
function spawn(callable $task, int $timeout = 600): PromiseInterface
{
    return Promise::resolved(
        ProcessManager::getGlobal()->spawnBackgroundTask($task, $timeout)
    );
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
 * @param callable(): TResult $task The task to execute in background
 * @param int $timeout Maximum seconds for the background process (default: 600)
 *
 * @return callable(): PromiseInterface<BackgroundProcess> A callable that returns a Promise resolving to the Process
 *
 * @example
 * ```php
 * // Using namespaced function (must be autoloaded)
 * namespace App\Logging;
 * function logToDb(string $msg) {
 *     Logger::toDb($msg);
 * }
 *
 * $logToDb = spawnFn('App\Logging\logToDb');
 *
 * // Spawns 3 background processes
 * $processes = await(Promise::map(['msg1', 'msg2', 'msg3'], $logToDb));
 * ```
 */
function spawnFn(callable $task, int $timeout = 600): callable
{
    return static function (mixed ...$args) use ($task, $timeout): PromiseInterface {
        return spawn(static fn () => $task(...$args), $timeout);
    };
}