<?php

namespace Hibla\Parallel;

use Hibla\Parallel\ProcessPool;
use Hibla\Parallel\Utilities\LazyTask;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

/**
 * Parallel task execution utilities integrated with Hibla's async ecosystem
 */
class Parallel
{
    /**
     * Execute multiple tasks concurrently and wait for all to complete
     * Returns a Promise that resolves when all tasks are done
     * 
     * @param array $tasks Array of callables, task IDs, or lazy task IDs (keys are preserved)
     * @param int|null $maxConcurrency Maximum concurrent processes (null = no limit)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @param int $pollIntervalMs Polling interval in milliseconds
     * @return PromiseInterface<array> Promise resolving to task results with preserved keys
     * @throws \RuntimeException If any task fails or times out
     */
    public static function all(
        array $tasks,
        ?int $maxConcurrency = null,
        int $timeoutSeconds = 60,
        int $pollIntervalMs = 10
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs) {
            if (empty($tasks)) {
                return [];
            }

            // Determine task types
            $taskTypes = self::analyzeTaskTypes($tasks);

            // Use pool if we have concurrency limit and lazy/callable tasks
            if (($taskTypes['hasLazy'] || $taskTypes['hasCallable']) && $maxConcurrency !== null) {
                return await(self::executeWithPool($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs));
            }

            // Convert lazy/callables to task IDs
            if ($taskTypes['hasLazy'] || $taskTypes['hasCallable']) {
                $tasks = await(self::convertToTaskIds($tasks));
            }

            // Wait for all tasks
            return await(self::awaitTaskIds($tasks, $timeoutSeconds));
        });
    }

    /**
     * Execute multiple tasks concurrently and return all results (settled version)
     * Returns a Promise that never throws - returns results with status indicators
     * 
     * @param array $tasks Array of callables, task IDs, or lazy task IDs (keys are preserved)
     * @param int $timeoutSeconds Maximum time to wait for all tasks
     * @param int|null $maxConcurrency Maximum concurrent processes (null = no limit)
     * @param int $pollIntervalMs Polling interval in milliseconds
     * @return PromiseInterface<array> Promise resolving to results with 'status' and either 'value' or 'reason' for each task
     */
    public static function allSettled(
        array $tasks,
        int $timeoutSeconds = 60,
        ?int $maxConcurrency = null,
        int $pollIntervalMs = 100
    ): PromiseInterface {
        return async(function () use ($tasks, $timeoutSeconds, $maxConcurrency, $pollIntervalMs) {
            if (empty($tasks)) {
                return [];
            }

            $taskTypes = self::analyzeTaskTypes($tasks);

            if (($taskTypes['hasLazy'] || $taskTypes['hasCallable']) && $maxConcurrency !== null) {
                return await(self::executeWithPoolSettled($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs));
            }

            if ($taskTypes['hasLazy'] || $taskTypes['hasCallable']) {
                $tasks = await(self::convertToTaskIds($tasks));
            }

            return await(self::awaitTaskIdsSettled($tasks, $timeoutSeconds));
        });
    }

    /**
     * Execute tasks with automatic cancellation on first failure (non-blocking)
     * 
     * @param array $tasks Array of callables or task IDs
     * @param int|null $maxConcurrency Maximum concurrent processes
     * @param int $timeoutSeconds Timeout for all tasks
     * @param int $pollIntervalMs Polling interval
     * @return PromiseInterface<array> Promise that resolves to task results
     */
    public static function allOrCancel(
        array $tasks,
        ?int $maxConcurrency = null,
        int $timeoutSeconds = 60,
        int $pollIntervalMs = 100
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs) {
            $taskIds = [];

            try {
                // Start all tasks
                foreach ($tasks as $key => $task) {
                    if (is_callable($task)) {
                        $taskIds[$key] = await(Process::spawn($task));
                    } else {
                        $taskIds[$key] = $task; // Already a task ID
                    }
                }

                // Wait for all tasks
                return await(self::all($taskIds, $maxConcurrency, $timeoutSeconds, $pollIntervalMs));
            } catch (\Throwable $e) {
                // Cancel all tasks that are still running
                $cancelled = 0;
                foreach ($taskIds as $taskId) {
                    if (Process::isRunning($taskId)) {
                        $result = Process::cancel($taskId);
                        if ($result['success']) {
                            $cancelled++;
                        }
                    }
                }

                throw new \RuntimeException(
                    $e->getMessage() . " (Cancelled {$cancelled} running tasks)",
                    0,
                    $e
                );
            }
        });
    }

    /**
     * Get information about all currently running tasks
     * 
     * @return array Array of running tasks with their status
     */
    public static function getRunningTasks(): array
    {
        return Process::getCancellableTasks();
    }

    /**
     * Cancel all currently running tasks
     * 
     * @return array Summary of cancellation results
     */
    public static function cancelAll(): array
    {
        return Process::cancelAll();
    }

    /**
     * Get statistics about settled task results
     * 
     * @param array $settledResults Results from allSettled()
     * @return array Statistics about the execution
     */
    public static function getStats(array $settledResults): array
    {
        $stats = [
            'total' => count($settledResults),
            'successful' => 0,
            'failed' => 0,
            'success_rate' => 0.0,
            'failures' => []
        ];

        foreach ($settledResults as $key => $result) {
            if ($result['status'] === 'fulfilled') {
                $stats['successful']++;
            } else {
                $stats['failed']++;
                $stats['failures'][$key] = $result['reason'];
            }
        }

        if ($stats['total'] > 0) {
            $stats['success_rate'] = round(($stats['successful'] / $stats['total']) * 100, 2);
        }

        return $stats;
    }

    /**
     * Analyze what types of tasks are in the array
     */
    private static function analyzeTaskTypes(array $tasks): array
    {
        $hasLazy = false;
        $hasCallable = false;

        foreach ($tasks as $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $hasLazy = true;
            } elseif (is_callable($item)) {
                $hasCallable = true;
            }
        }

        return [
            'hasLazy' => $hasLazy,
            'hasCallable' => $hasCallable
        ];
    }

    /**
     * Convert mixed task types to actual task IDs
     */
    private static function convertToTaskIds(array $tasks): PromiseInterface
    {
        return async(function () use ($tasks) {
            $taskIds = [];

            foreach ($tasks as $key => $item) {
                if (\is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if (!$lazyTask) {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                    $taskIds[$key] = await($lazyTask->execute());
                } elseif (is_callable($item)) {
                    $taskIds[$key] = await(Process::spawn($item));
                } else {
                    $taskIds[$key] = $item; // Already a task ID
                }
            }

            return $taskIds;
        });
    }

    /**
     * Execute tasks using process pool
     */
    private static function executeWithPool(
        array $tasks,
        int $maxConcurrency,
        int $timeoutSeconds,
        int $pollIntervalMs
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs) {
            $pool = new ProcessPool($maxConcurrency, $pollIntervalMs);

            // Convert to pool format
            $poolTasks = [];
            foreach ($tasks as $key => $item) {
                if (\is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if (!$lazyTask) {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                    $poolTasks[$key] = [
                        'callback' => $lazyTask->getCallback(),
                        'context' => $lazyTask->getContext()
                    ];
                } elseif (is_callable($item)) {
                    $poolTasks[$key] = ['callback' => $item, 'context' => []];
                } else {
                    throw new \RuntimeException("Cannot mix lazy/callable with task IDs in pool mode");
                }
            }

            // Execute through pool
            $taskIds = await($pool->executeTasksAsync($poolTasks));
            $poolTimeout = min($timeoutSeconds, 60);
            $completed = await($pool->waitForCompletionAsync($poolTimeout));

            if (!$completed) {
                throw new \RuntimeException("Pool execution timed out during startup phase");
            }

            // Wait for results
            return await(self::awaitTaskIds($taskIds, $timeoutSeconds));
        });
    }

    /**
     * Execute with pool (settled version)
     */
    private static function executeWithPoolSettled(
        array $tasks,
        int $maxConcurrency,
        int $timeoutSeconds,
        int $pollIntervalMs
    ): PromiseInterface {
        return async(function () use ($tasks, $maxConcurrency, $timeoutSeconds, $pollIntervalMs) {
            $pool = new ProcessPool($maxConcurrency, $pollIntervalMs);

            $poolTasks = [];
            foreach ($tasks as $key => $item) {
                if (\is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if (!$lazyTask) {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                    $poolTasks[$key] = [
                        'callback' => $lazyTask->getCallback(),
                        'context' => $lazyTask->getContext()
                    ];
                } elseif (is_callable($item)) {
                    $poolTasks[$key] = ['callback' => $item, 'context' => []];
                } else {
                    throw new \RuntimeException("Cannot mix lazy/callable with task IDs in pool mode");
                }
            }

            $taskIds = await($pool->executeTasksAsync($poolTasks));
            $poolTimeout = min($timeoutSeconds, 60);
            await($pool->waitForCompletionAsync($poolTimeout));

            return await(self::awaitTaskIdsSettled($taskIds, $timeoutSeconds));
        });
    }

    /**
     * Wait for task IDs to complete
     */
    private static function awaitTaskIds(array $taskIds, int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($taskIds, $timeoutSeconds) {
            $startTime = time();
            $results = [];
            $completedTasks = [];
            $displayedOutput = [];

            foreach ($taskIds as $key => $taskId) {
                $results[$key] = null;
            }

            while (true) {
                $allCompleted = true;

                foreach ($taskIds as $key => $taskId) {
                    if (isset($completedTasks[$key])) {
                        continue;
                    }

                    $status = Process::getTaskStatus($taskId);

                    if (isset($status['output']) && !empty($status['output']) && !isset($displayedOutput[$taskId])) {
                        echo $status['output'];
                        $displayedOutput[$taskId] = true;
                    }

                    if ($status['status'] === 'COMPLETED') {
                        $results[$key] = $status['result'] ?? null;
                        $completedTasks[$key] = true;

                        // Display output one more time for completed tasks
                        if (isset($status['output']) && !empty($status['output'])) {
                            if (!isset($displayedOutput[$taskId])) {
                                echo $status['output'];
                                $displayedOutput[$taskId] = true;
                            }
                        }
                    } elseif ($status['status'] === 'ERROR' || $status['status'] === 'NOT_FOUND') {
                        $errorMsg = $status['error_message'] ?? $status['message'];
                        throw new \RuntimeException("Task {$taskId} (key: {$key}) failed: {$errorMsg}");
                    } else {
                        // Task still running
                        $allCompleted = false;
                    }
                }

                if ($allCompleted) {
                    return $results;
                }

                if ($timeoutSeconds > 0 && time() - $startTime >= $timeoutSeconds) {
                    $pendingTasks = [];
                    foreach ($taskIds as $key => $taskId) {
                        if (!isset($completedTasks[$key])) {
                            $pendingTasks[] = "{$taskId} (key: {$key})";
                        }
                    }
                    $pendingList = implode(', ', $pendingTasks);
                    throw new \RuntimeException("Tasks timed out after {$timeoutSeconds} seconds. Pending: {$pendingList}");
                }

                await(delay(0.01)); 
            }
        });
    }

    /**
     * Wait for task IDs (settled version - never throws)
     */
    private static function awaitTaskIdsSettled(array $taskIds, int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($taskIds, $timeoutSeconds) {
            $startTime = time();
            $results = [];
            $completedTasks = [];
            $displayedOutput = [];

            foreach ($taskIds as $key => $taskId) {
                $results[$key] = null;
            }

            while (true) {
                $allCompleted = true;

                foreach ($taskIds as $key => $taskId) {
                    if (isset($completedTasks[$key])) {
                        continue;
                    }

                    $status = Process::getTaskStatus($taskId);

                    // Display output
                    if (isset($status['output']) && !empty($status['output']) && !isset($displayedOutput[$taskId])) {
                        echo $status['output'];
                        $displayedOutput[$taskId] = true;
                    }

                    if ($status['status'] === 'COMPLETED') {
                        $results[$key] = [
                            'status' => 'fulfilled',
                            'value' => $status['result'] ?? null,
                            'task_id' => $taskId
                        ];
                        $completedTasks[$key] = true;

                        // Display output one more time for completed tasks
                        if (isset($status['output']) && !empty($status['output'])) {
                            if (!isset($displayedOutput[$taskId])) {
                                echo $status['output'];
                                $displayedOutput[$taskId] = true;
                            }
                        }
                    } elseif ($status['status'] === 'ERROR' || $status['status'] === 'NOT_FOUND') {
                        $errorMsg = $status['error_message'] ?? $status['message'];
                        $results[$key] = [
                            'status' => 'rejected',
                            'reason' => $errorMsg,
                            'task_id' => $taskId
                        ];
                        $completedTasks[$key] = true;

                        // Display output even for failed tasks
                        if (isset($status['output']) && !empty($status['output'])) {
                            if (!isset($displayedOutput[$taskId])) {
                                echo $status['output'];
                                $displayedOutput[$taskId] = true;
                            }
                        }
                    } else {
                        $allCompleted = false;
                    }
                }

                if ($allCompleted) {
                    return $results;
                }

                // Timeout - return what we have
                if ($timeoutSeconds > 0 && time() - $startTime >= $timeoutSeconds) {
                    foreach ($taskIds as $key => $taskId) {
                        if (!isset($completedTasks[$key])) {
                            $results[$key] = [
                                'status' => 'rejected',
                                'reason' => "Timeout after {$timeoutSeconds} seconds",
                                'task_id' => $taskId
                            ];
                        }
                    }
                    return $results;
                }

                await(delay(0.01));
            }
        });
    }
}