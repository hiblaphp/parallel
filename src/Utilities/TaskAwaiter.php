<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

class TaskAwaiter
{
    /**
     * Wait for multiple tasks to complete and return their results (non-blocking)
     */
    public static function awaitAll(
        array $taskIds,
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): PromiseInterface {
        return async(function () use ($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs) {
            if (empty($taskIds)) {
                return [];
            }

            // Check what types of tasks we have
            $hasLazyTasks = false;
            $hasCallables = false;

            foreach ($taskIds as $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $hasLazyTasks = true;
                } elseif (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                    $hasCallables = true;
                }
            }

            // If we have lazy tasks or callables and a process pool limit, use the pool
            if (($hasLazyTasks || $hasCallables) && $maxConcurrentTasks !== null) {
                return await(self::awaitAllWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs));
            }

            // If we have lazy tasks or callables but no pool limit, execute them all immediately
            if ($hasLazyTasks || $hasCallables) {
                $actualTaskIds = [];
                foreach ($taskIds as $key => $item) {
                    if (is_string($item) && LazyTask::isLazyId($item)) {
                        $lazyTask = LazyTask::get($item);
                        if ($lazyTask) {
                            $actualTaskIds[$key] = await($lazyTask->execute());
                        } else {
                            throw new \RuntimeException("Lazy task not found: {$item}");
                        }
                    } elseif (is_callable($item)) {
                        $actualTaskIds[$key] = await(Process::spawn($item));
                    } elseif (is_array($item) && isset($item['callback'])) {
                        $actualTaskIds[$key] = await(Process::spawn($item['callback'], $item['context'] ?? []));
                    } else {
                        $actualTaskIds[$key] = $item; // Assume it's already a task ID
                    }
                }
                $taskIds = $actualTaskIds;
            }

            // Wait for all task IDs with automatic output display
            return await(self::awaitTaskIdsWithOutput($taskIds, $timeoutSeconds));
        });
    }

    /**
     * Await tasks using process pool (non-blocking)
     */
    private static function awaitAllWithPool(
        array $tasks,
        int $timeoutSeconds,
        int $maxConcurrentTasks,
        int $pollIntervalMs
    ): PromiseInterface {
        return async(function () use ($tasks, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs) {
            $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);

            // Convert all items to pool-compatible format
            $poolTasks = [];
            foreach ($tasks as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $poolTasks[$key] = [
                            'callback' => $lazyTask->getCallback(),
                            'context' => $lazyTask->getContext()
                        ];
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
                    $poolTasks[$key] = ['callback' => $item, 'context' => []];
                } elseif (is_array($item) && isset($item['callback'])) {
                    $poolTasks[$key] = $item;
                } else {
                    throw new \RuntimeException("Cannot mix lazy/callable tasks with existing task IDs in pool mode");
                }
            }

            // Execute tasks through pool
            $taskIds = await($pool->executeTasksAsync($poolTasks));

            // Wait for pool completion
            $poolTimeout = min($timeoutSeconds, 60);
            $completed = await($pool->waitForCompletionAsync($poolTimeout));

            if (!$completed) {
                throw new \RuntimeException("Pool execution timed out during startup phase");
            }

            // Now await all results with output display
            return await(self::awaitTaskIdsWithOutput($taskIds, $timeoutSeconds));
        });
    }

    /**
     * Wait for task IDs with automatic output display (non-blocking)
     */
    private static function awaitTaskIdsWithOutput(array $taskIds, int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($taskIds, $timeoutSeconds) {
            $startTime = time();
            $results = [];
            $completedTasks = [];
            $failedTasks = [];
            $displayedOutput = [];

            // Initialize results array with preserved keys
            foreach ($taskIds as $key => $taskId) {
                $results[$key] = null;
            }

            while (true) {
                $allCompleted = true;

                foreach ($taskIds as $key => $taskId) {
                    // Skip already processed tasks
                    if (isset($completedTasks[$key]) || isset($failedTasks[$key])) {
                        continue;
                    }

                    $status = Process::getTaskStatus($taskId);

                    // Display output as soon as it's available
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
                        $failedTasks[$key] = $status;
                        $allCompleted = false;
                        break; // Exit early on first failure
                    } else {
                        // Task is still pending or running
                        $allCompleted = false;
                    }
                }

                // Check if any task failed
                if (!empty($failedTasks)) {
                    $failedKey = array_key_first($failedTasks);
                    $failedStatus = $failedTasks[$failedKey];
                    $taskId = $taskIds[$failedKey];
                    $errorMsg = $failedStatus['error_message'] ?? $failedStatus['message'];
                    throw new \RuntimeException("Task {$taskId} (key: {$failedKey}) failed: {$errorMsg}");
                }

                // All tasks completed successfully
                if ($allCompleted) {
                    return $results;
                }

                // Check timeout
                if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                    $pendingTasks = [];
                    foreach ($taskIds as $key => $taskId) {
                        if (!isset($completedTasks[$key])) {
                            $pendingTasks[] = "{$taskId} (key: {$key})";
                        }
                    }
                    $pendingList = implode(', ', $pendingTasks);
                    throw new \RuntimeException("Tasks timed out after {$timeoutSeconds} seconds. Pending: {$pendingList}");
                }

                // Non-blocking delay using event loop
                await(delay(0.01)); // 10ms delay
            }
        });
    }

    /**
     * Wait for multiple tasks to complete and return all results (settled version, non-blocking)
     */
    public static function awaitAllSettled(
        array $taskIds,
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): PromiseInterface {
        return async(function () use ($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs) {
            if (empty($taskIds)) {
                return [];
            }

            // Check what types of tasks we have
            $hasLazyTasks = false;
            $hasCallables = false;

            foreach ($taskIds as $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $hasLazyTasks = true;
                } elseif (is_callable($item) || (is_array($item) && isset($item['callback']))) {
                    $hasCallables = true;
                }
            }

            // Use same logic as awaitAll but with settled behavior
            if (($hasLazyTasks || $hasCallables) && $maxConcurrentTasks !== null) {
                return await(self::awaitAllSettledWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs));
            }

            if ($hasLazyTasks || $hasCallables) {
                $actualTaskIds = [];
                foreach ($taskIds as $key => $item) {
                    if (is_string($item) && LazyTask::isLazyId($item)) {
                        $lazyTask = LazyTask::get($item);
                        if ($lazyTask) {
                            $actualTaskIds[$key] = await($lazyTask->execute());
                        } else {
                            throw new \RuntimeException("Lazy task not found: {$item}");
                        }
                    } elseif (is_callable($item)) {
                        $actualTaskIds[$key] = await(Process::spawn($item));
                    } elseif (is_array($item) && isset($item['callback'])) {
                        $actualTaskIds[$key] = await(Process::spawn($item['callback'], $item['context'] ?? []));
                    } else {
                        $actualTaskIds[$key] = $item;
                    }
                }
                $taskIds = $actualTaskIds;
            }

            return await(self::awaitTaskIdsSettledWithOutput($taskIds, $timeoutSeconds));
        });
    }

    private static function awaitAllSettledWithPool(
        array $tasks,
        int $timeoutSeconds,
        int $maxConcurrentTasks,
        int $pollIntervalMs
    ): PromiseInterface {
        return async(function () use ($tasks, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs) {
            $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);

            $poolTasks = [];
            foreach ($tasks as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $poolTasks[$key] = [
                            'callback' => $lazyTask->getCallback(),
                            'context' => $lazyTask->getContext()
                        ];
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
                    $poolTasks[$key] = ['callback' => $item, 'context' => []];
                } elseif (is_array($item) && isset($item['callback'])) {
                    $poolTasks[$key] = $item;
                } else {
                    throw new \RuntimeException("Cannot mix lazy/callable tasks with existing task IDs in pool mode");
                }
            }

            $taskIds = await($pool->executeTasksAsync($poolTasks));
            $poolTimeout = min($timeoutSeconds, 60);
            await($pool->waitForCompletionAsync($poolTimeout));

            return await(self::awaitTaskIdsSettledWithOutput($taskIds, $timeoutSeconds));
        });
    }

    private static function awaitTaskIdsSettledWithOutput(array $taskIds, int $timeoutSeconds): PromiseInterface
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

                    // Display output as soon as it's available
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

                if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
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

    /**
     * Wait for tasks with automatic cancellation on first failure (non-blocking)
     */
    public static function awaitAllOrCancel(
        array $taskIds,
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): PromiseInterface {
        return async(function () use ($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs) {
            try {
                return await(self::awaitAll($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs));
            } catch (\Throwable $e) {
                $cancelled = [];
                foreach ($taskIds as $key => $taskId) {
                    $status = Process::getTaskStatus($taskId);
                    if (in_array($status['status'], ['RUNNING', 'PENDING'])) {
                        $result = await(Process::cancel($taskId));
                        if ($result['success']) {
                            $cancelled[$key] = $taskId;
                        }
                    }
                }

                if (!empty($cancelled)) {
                    throw new \RuntimeException(
                        $e->getMessage() . sprintf(' (Cancelled %d running tasks)', count($cancelled)),
                        0,
                        $e
                    );
                }

                throw $e;
            }
        });
    }
}
