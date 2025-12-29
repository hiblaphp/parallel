<?php

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\ProcessPool;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

/**
 * A high-level facade for running tasks in parallel with concurrency control and timeouts.
 * This class provides the simplest entry point for parallel execution.
 */
class Parallel
{
    /**
     * Executes multiple tasks concurrently with a specified concurrency limit.
     *
     * The returned promise will resolve with an array of results, preserving the
     * keys from the input array. The promise will reject if any single task
     * fails or if the overall operation exceeds the specified timeout.
     *
     * The returned promise can be cancelled, which will terminate all running processes.
     *
     * @template TResult
     * @param array<int|string, callable(): TResult> $tasks An associative array of callables to execute in parallel.
     * @param int $maxProcess The maximum number of processes to run at the same time. Defaults to 8.
     * @param float $timeoutSeconds An overall timeout for the entire operation in seconds. If exceeded,
     *                              the returned promise will reject. Defaults to 60.0.
     * @return PromiseInterface<array<int|string, TResult>> A promise that resolves to an array of results with keys preserved.
     * @throws \RuntimeException If the operation times out
     * @throws \Throwable If any task fails
     *
     * @example
     * ```php
     * // Basic usage
     * $results = await(Parallel::all([
     *     'image1' => fn() => processImage('path1.jpg'),
     *     'image2' => fn() => processImage('path2.jpg'),
     * ], maxProcess: 4, timeoutSeconds: 30.0));
     * 
     * // With cancellation
     * $promise = Parallel::all($tasks, maxProcess: 8);
     * // Later: $promise->cancel();
     * 
     * // With timeout using Promise::timeout
     * try {
     *     $results = await(Promise::timeout(
     *         Parallel::all($tasks, maxProcess: 4),
     *         30.0
     *     ));
     * } catch (TimeoutException $e) {
     *     // All processes automatically terminated
     * }
     * ```
     */
    public static function all(
        array $tasks,
        int $maxProcess = 8,
        float $timeoutSeconds = 60.0
    ): PromiseInterface {
        $source = new CancellationTokenSource();

        return async(function () use ($tasks, $maxProcess, $timeoutSeconds, $source) {
            if (empty($tasks)) {
                return [];
            }

            $pool = new ProcessPool($maxProcess);
            $poolPromise = $pool->run($tasks);
            $timeoutPromise = Promise::timeout($poolPromise, $timeoutSeconds);

            $source->token->onCancel(function () use ($poolPromise, $timeoutPromise) {
                $poolPromise->cancel();
                $timeoutPromise->cancel();
            });

            try {
                return await($timeoutPromise);
            } catch (TimeoutException $e) {
                throw new \RuntimeException(
                    "Parallel::all operation timed out after {$timeoutSeconds} seconds.",
                    0,
                    $e
                );
            }
        })->onCancel(function () use ($source) {
            $source->cancel();
        });
    }

    /**
     * Executes multiple tasks concurrently and returns a settled result for each.
     *
     * This promise will never reject due to task failures. Individual task failures
     * are captured in TaskResult objects. The promise may reject if the overall
     * timeout is exceeded or if the operation is cancelled.
     *
     * The returned promise can be cancelled, which will terminate all running processes.
     *
     * @template TResult
     * @param array<int|string, callable(): TResult> $tasks An associative array of callables to execute.
     * @param int $maxProcess The maximum number of processes to run at the same time. Defaults to 8.
     * @param float $timeoutSeconds An overall timeout for the entire operation in seconds. Any tasks
     *                              not completed by this time will be marked as rejected.
     * @return PromiseInterface<array<int|string, TaskResult<TResult>>> A promise resolving to an array of TaskResult objects.
     * @throws \RuntimeException If the operation times out
     *
     * @example
     * ```php
     * // Basic usage
     * $results = await(Parallel::allSettled([
     *     'task1' => fn() => riskyOperation1(),
     *     'task2' => fn() => riskyOperation2(),
     * ]));
     * 
     * foreach ($results as $key => $result) {
     *     if ($result->isFulfilled()) {
     *         echo "Task {$key} succeeded: " . $result->getValue() . "\n";
     *     } else {
     *         echo "Task {$key} failed: " . $result->getReason() . "\n";
     *     }
     * }
     * 
     * // With cancellation
     * $promise = Parallel::allSettled($tasks);
     * // Later: $promise->cancel();
     * 
     * // Convert to arrays if needed
     * $results = await(Parallel::allSettled($tasks));
     * $arrays = array_map(fn($r) => $r->toArray(), $results);
     * ```
     */
    public static function allSettled(
        array $tasks,
        int $maxProcess = 8,
        float $timeoutSeconds = 60.0
    ): PromiseInterface {
        $source = new CancellationTokenSource();

        return async(function () use ($tasks, $maxProcess, $timeoutSeconds, $source) {
            if (empty($tasks)) {
                return [];
            }

            $pool = new ProcessPool($maxProcess);
            $poolPromise = $pool->runSettled($tasks);
            $timeoutPromise = Promise::timeout($poolPromise, $timeoutSeconds);

            $source->token->onCancel(function () use ($poolPromise, $timeoutPromise) {
                $poolPromise->cancel();
                $timeoutPromise->cancel();
            });

            try {
                return await($timeoutPromise);
            } catch (TimeoutException $e) {
                throw new \RuntimeException(
                    "Parallel::allSettled operation timed out after {$timeoutSeconds} seconds.",
                    0,
                    $e
                );
            }
        })->onCancel(function () use ($source) {
            $source->cancel();
        });
    }

    /**
     * Map an array of items through a function in parallel.
     *
     * This is a convenience method for transforming data in parallel,
     * similar to array_map but with concurrent execution.
     *
     * @template TInput
     * @template TOutput
     * @param array<int|string, TInput> $items Items to transform
     * @param callable(TInput): TOutput $mapper Function to apply to each item
     * @param int $maxProcess Maximum number of concurrent processes. Defaults to 8.
     * @param float $timeoutSeconds Overall timeout in seconds. Defaults to 60.0.
     * @return PromiseInterface<array<int|string, TOutput>> A promise resolving to transformed items
     * @throws \RuntimeException If the operation times out
     * @throws \Throwable If any transformation fails
     *
     * @example
     * ```php
     * $images = ['img1.jpg', 'img2.jpg', 'img3.jpg'];
     * 
     * $processed = await(Parallel::map(
     *     $images,
     *     fn($path) => processImage($path),
     *     maxProcess: 4
     * ));
     * ```
     */
    public static function map(
        array $items,
        callable $mapper,
        int $maxProcess = 8,
        float $timeoutSeconds = 60.0
    ): PromiseInterface {
        $tasks = [];
        foreach ($items as $key => $item) {
            $tasks[$key] = fn() => $mapper($item);
        }

        return self::all($tasks, $maxProcess, $timeoutSeconds);
    }
}
