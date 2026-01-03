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
 * ⚠️ CLOSURE SERIALIZATION WARNING
 *
 * The way you format closures affects what gets captured during serialization:
 *
 * ❌ DANGEROUS (may cause fork bomb and the process spawner immediately throws an exception to prevent mass spawn):
 * async(fn() => await(parallel(fn() => sleep(5))));
 *
 * ✅ SAFE (multi-line formatting):
 * async(
 *     fn() => await(parallel(
 *         fn() => sleep(5)
 *     ))
 * );
 *
 * ✅ SAFEST (use regular closures):
 * async(function() {
 *     return await(parallel(function() {
 *         return sleep(5);
 *     }));
 * });
 *
 * ✅ BEST (avoid nesting entirely):
 * await(parallel(fn() => sleep(5)));
 *
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in parallel
 * @param int $timeout Maximum seconds to wait for task completion (default: 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 *
 * @example
 * ```php
 * // Simple parallel execution
 * $result = await(parallel(fn() => heavyComputation()));
 *
 * // With context
 * $result = await(parallel(
 *     fn($ctx) => processData($ctx['data']),
 *     ['data' => $myData]
 * ));
 *
 * // With timeout
 * $result = await(parallel(fn() => slowTask(), [], 120));
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
        return parallel(static fn() => $task(...$args), $timeout);
    };
}

/**
 * Spawn a background process and return the Process instance for manual control.
 *
 * Unlike `parallel()`, this gives you full control over the process lifecycle.
 * You must manually call `await()` on the returned process to get the result.
 *
 * ⚠️ CLOSURE SERIALIZATION WARNING
 *
 * The way you format closures affects what gets captured during serialization:
 *
 * ❌ DANGEROUS (may cause fork bomb):
 * async(fn() => await(spawn(fn() => sleep(5))));
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
 * @return PromiseInterface<BackgroundProcess> Promise resolving to the Process instance
 *
 * @throws \RuntimeException If process spawning fails
 * @throws SerializationException If task cannot be serialized
 *
 * @example
 * ```php
 *
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
 * @template TResult
 *
 * @param callable(): TResult $task The task to execute in background
 * @param int $timeout Maximum seconds for the background process (default: 600)
 * 
 * @return callable(): PromiseInterface<BackgroundProcess> A callable that returns a Promise resolving to the Process
 *
 * @example
 * ```php
 * $logToDb = spawnFn(function(string $msg) {
 *     Logger::toDb($msg);
 * });
 *
 * // Spawns 3 background processes
 * $processes = await(Promise::map(['msg1', 'msg2', 'msg3'], $logToDb));
 * ```
 */
function spawnFn(callable $task, int $timeout = 600): callable
{
    return static function (mixed ...$args) use ($task, $timeout): PromiseInterface {
        return spawn(static fn() => $task(...$args), $timeout);
    };
}
