<?php 

namespace Hibla;

use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Run a task in parallel (separate process) and return a Promise.
 *
 * This is a convenience function for executing CPU-bound tasks in parallel
 * without blocking the event loop. The task runs in a separate PHP process.
 *
 * **Use Cases:**
 * - Image processing
 * - Data transformation
 * - Heavy computations
 * - PDF generation
 * - Any CPU-intensive work
 *
 * **Examples:**
 * ```php
 * // Simple parallel execution
 * $result = await(parallel(fn() => heavyComputation()));
 *
 * // With context/parameters
 * $result = await(parallel(
 *     fn($ctx) => processImage($ctx['path']),
 *     ['path' => 'image.jpg']
 * ));
 *
 * // With timeout
 * try {
 *     $result = await(parallel(fn() => longRunningTask(), timeout: 10));
 * } catch (\RuntimeException $e) {
 *     echo "Task timed out!";
 * }
 *
 * // Multiple parallel tasks
 * $results = await(Promise::all([
 *     parallel(fn() => task1()),
 *     parallel(fn() => task2()),
 *     parallel(fn() => task3()),
 * ]));
 * ```
 *
 * **Note:** For multiple tasks with concurrency control, use `Parallel::all()`:
 * ```php
 * use Hibla\Parallel\Parallel;
 * 
 * $results = await(Parallel::all($tasks, maxConcurrency: 4));
 * ```
 *
 * @template TResult The return type of the parallel task
 *
 * @param  callable(array): TResult  $task  The task to execute in parallel
 * @param  array<string, mixed>  $context  Optional context/parameters to pass to the task
 * @param  int  $timeout  Maximum seconds to wait for task completion (default: 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 *
 * @throws \RuntimeException If the task fails or times out
 * @throws \Hibla\Parallel\Serialization\SerializationException If task cannot be serialized
 */
function parallel(callable $task, array $context = [], int $timeout = 60): PromiseInterface
{
    return async(function () use ($task, $context, $timeout) {
        $taskId = await(Process::spawn($task, $context));
        
        return await(Process::await($taskId, $timeout));
    });
}