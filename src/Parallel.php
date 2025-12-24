<?php

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\ProcessPool;
use Hibla\Promise\Exceptions\PromiseCancelledException;
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
     * Example:
     * ```php
     * $results = await(Parallel::all([
     *     'image1' => fn() => processImage('path1.jpg'),
     *     'image2' => fn() => processImage('path2.jpg'),
     * ], maxConcurrency: 4, timeoutSeconds: 30));
     * ```
     *
     * @param array<string|int, callable> $tasks An associative array of callables to execute in parallel.
     * @param int $maxConcurrency The maximum number of processes to run at the same time. Defaults to 8.
     * @param int $timeoutSeconds An overall timeout for the entire operation in seconds. If it's exceeded,
     *                            the returned promise will reject. Defaults to 60.
     * @return PromiseInterface<array> A promise that resolves to an array of results with keys preserved.
     */
    public static function all(
        array $tasks,
        int $maxConcurrency = 8,
        int $timeoutSeconds = 60,
        ?CancellationToken $cancellation = null
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $cancellation) {
            if (empty($tasks)) {
                return [];
            }

            $pool = new ProcessPool($maxConcurrency);
            $poolPromise = $pool->run($tasks, $cancellation);

            try {
                return await(Promise::timeout($poolPromise, $timeoutSeconds));
            } catch (TimeoutException $e) {
                throw new \RuntimeException(
                    "Parallel::all operation timed out after {$timeoutSeconds} seconds.",
                    0,
                    $e
                );
            }
        });
    }

    /**
     * Executes multiple tasks concurrently and returns a settled result for each.
     *
     * This promise will never reject, even if individual tasks fail or the timeout is exceeded.
     * Instead, it resolves with an array where each result is an object containing
     * a `status` ('fulfilled' or 'rejected') and either a 'value' or a 'reason'.
     *
     * Example:
     * ```php
     * $results = await(Parallel::allSettled([...]));
     * foreach ($results as $key => $result) {
     *     if ($result['status'] === 'fulfilled') {
     *         echo "Task {$key} succeeded with value: " . $result['value'];
     *     } else {
     *         echo "Task {$key} failed with reason: " . $result['reason'];
     *     }
     * }
     * ```
     *
     * @param array<string|int, callable> $tasks An associative array of callables to execute.
     * @param int $maxConcurrency The maximum number of processes to run at the same time. Defaults to 8.
     * @param int $timeoutSeconds An overall timeout for the entire operation in seconds. Any tasks
     *                            not completed by this time will be marked as rejected.
     * @return PromiseInterface<array> A promise resolving to an array of settled result objects.
     */
    public static function allSettled(
        array $tasks,
        int $maxConcurrency = 8,
        int $timeoutSeconds = 60,
        ?CancellationToken $cancellation = null
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $cancellation) {
            if (empty($tasks)) {
                return [];
            }

            $pool = new ProcessPool($maxConcurrency);

            try {
                return await(Promise::timeout(
                    $pool->runSettled($tasks, $cancellation),
                    $timeoutSeconds
                ));
            } catch (TimeoutException $e) {
                throw new \RuntimeException(
                    "Parallel::allSettled operation timed out after {$timeoutSeconds} seconds.",
                    0,
                    $e
                );
            }
        });
    }
}
