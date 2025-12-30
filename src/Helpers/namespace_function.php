<?php

namespace Hibla;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\BackgroundProcess;
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
 * @param array<string, mixed> $context Optional context/parameters to pass to the task
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
function parallel(callable $task, array $context = [], int $timeout = 60): PromiseInterface
{
    $source = new CancellationTokenSource();

    /** @var Process $process */
    $process = ProcessManager::getGlobal()->spawnStreamedTask($task, $context, $timeout);

    $source->token->onCancel(function () use ($process) {
        $process->terminate();
    });

    return $process->getResult($timeout)
        ->onCancel(function () use ($source) {
            $source->cancel();
        });
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
 * @param array<string, mixed> $context Optional context/parameters to pass to the task
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
function spawn(callable $task, array $context = [], int $timeout = 600): PromiseInterface
{
    return Promise::resolved(
        ProcessManager::getGlobal()->spawnBackgroundTask($task, $context, $timeout)
    );
}
