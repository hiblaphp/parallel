<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Defer;
use Hibla\Parallel\Process;

class TaskAwaiter
{
    /**
     * Wait for multiple tasks to complete and return their results
     */
    public static function awaitAll(
        array $taskIds, 
        int $timeoutSeconds = 60, 
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): array {
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
            return self::awaitAllWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        // If we have lazy tasks or callables but no pool limit, execute them all immediately
        if ($hasLazyTasks || $hasCallables) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $actualTaskIds[$key] = $lazyTask->execute();
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
                    $actualTaskIds[$key] = Process::spawn($item);
                } elseif (is_array($item) && isset($item['callback'])) {
                    $actualTaskIds[$key] = Process::spawn($item['callback'], $item['context'] ?? []);
                } else {
                    $actualTaskIds[$key] = $item; // Assume it's already a task ID
                }
            }
            $taskIds = $actualTaskIds;
        }

        // Original implementation for task IDs with automatic output display
        return self::awaitTaskIdsWithOutput($taskIds, $timeoutSeconds);
    }

    /**
     * Await tasks using process pool (handles lazy tasks properly without reflection)
     */
    private static function awaitAllWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        // Convert all items to pool-compatible format
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $lazyTask = LazyTask::get($item);
                if ($lazyTask) {
                    $poolTasks[$key] = [
                        'callback' => $lazyTask->getCallback(), // Use public method instead of reflection
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
                // Already a task ID, handle separately - but this shouldn't happen in pool mode
                throw new \RuntimeException("Cannot mix lazy/callable tasks with existing task IDs in pool mode");
            }
        }

        // Execute tasks through pool (this respects the concurrent limit)
        $taskIds = $pool->executeTasks($poolTasks);

        // Wait for pool completion
        $poolTimeout = min($timeoutSeconds, 60);
        if (!$pool->waitForCompletion($poolTimeout)) {
            throw new \RuntimeException("Pool execution timed out during startup phase");
        }

        // Now await all results with output display
        return self::awaitTaskIdsWithOutput($taskIds, $timeoutSeconds);
    }

    /**
     * Wait for task IDs with automatic output display
     */
    private static function awaitTaskIdsWithOutput(array $taskIds, int $timeoutSeconds): array
    {
        $startTime = time();
        $results = [];
        $completedTasks = [];
        $failedTasks = [];
        $displayedOutput = []; // Track which tasks' output we've already displayed

        // Initialize results array with preserved keys
        foreach ($taskIds as $key => $taskId) {
            $results[$key] = null;
        }

        do {
            $allCompleted = true;
            
            foreach ($taskIds as $key => $taskId) {
                // Skip already processed tasks
                if (isset($completedTasks[$key]) || isset($failedTasks[$key])) {
                    continue;
                }

                $status = Process::getTaskStatus($taskId);
                
                // Display output as soon as it's available (even for running tasks)
                if (isset($status['output']) && !empty($status['output']) && !isset($displayedOutput[$taskId])) {
                    echo $status['output'];
                    $displayedOutput[$taskId] = true;
                }
                
                if ($status['status'] === 'COMPLETED') {
                    $results[$key] = $status['result'] ?? null;
                    $completedTasks[$key] = true;
                    
                    // Display output one more time for completed tasks (in case it was updated)
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

            usleep(10000); // Wait 10ms before next check

        } while (true);
    }

    /**
     * Wait for multiple tasks to complete and return all results (settled version)
     */
    public static function awaitAllSettled(
        array $taskIds, 
        int $timeoutSeconds = 60,
        ?int $maxConcurrentTasks = null,
        int $pollIntervalMs = 100
    ): array {
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
            return self::awaitAllSettledWithPool($taskIds, $timeoutSeconds, $maxConcurrentTasks, $pollIntervalMs);
        }

        if ($hasLazyTasks || $hasCallables) {
            $actualTaskIds = [];
            foreach ($taskIds as $key => $item) {
                if (is_string($item) && LazyTask::isLazyId($item)) {
                    $lazyTask = LazyTask::get($item);
                    if ($lazyTask) {
                        $actualTaskIds[$key] = $lazyTask->execute();
                    } else {
                        throw new \RuntimeException("Lazy task not found: {$item}");
                    }
                } elseif (is_callable($item)) {
                    $actualTaskIds[$key] = Process::spawn($item);
                } elseif (is_array($item) && isset($item['callback'])) {
                    $actualTaskIds[$key] = Process::spawn($item['callback'], $item['context'] ?? []);
                } else {
                    $actualTaskIds[$key] = $item;
                }
            }
            $taskIds = $actualTaskIds;
        }

        return self::awaitTaskIdsSettledWithOutput($taskIds, $timeoutSeconds);
    }

    private static function awaitAllSettledWithPool(
        array $tasks, 
        int $timeoutSeconds, 
        int $maxConcurrentTasks, 
        int $pollIntervalMs
    ): array {
        $pool = new ProcessPool($maxConcurrentTasks, $pollIntervalMs);
        
        $poolTasks = [];
        foreach ($tasks as $key => $item) {
            if (is_string($item) && LazyTask::isLazyId($item)) {
                $lazyTask = LazyTask::get($item);
                if ($lazyTask) {
                    $poolTasks[$key] = [
                        'callback' => $lazyTask->getCallback(), // Use public method instead of reflection
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

        $taskIds = $pool->executeTasks($poolTasks);
        $poolTimeout = min($timeoutSeconds, 60);
        $pool->waitForCompletion($poolTimeout);

        return self::awaitTaskIdsSettledWithOutput($taskIds, $timeoutSeconds);
    }

    private static function awaitTaskIdsSettledWithOutput(array $taskIds, int $timeoutSeconds): array
    {
        $startTime = time();
        $results = [];
        $completedTasks = [];
        $displayedOutput = []; // Track which tasks' output we've already displayed

        foreach ($taskIds as $key => $taskId) {
            $results[$key] = null;
        }

        do {
            $allCompleted = true;
            
            foreach ($taskIds as $key => $taskId) {
                if (isset($completedTasks[$key])) {
                    continue;
                }

                $status = Process::getTaskStatus($taskId);
                
                // Display output as soon as it's available (even for running tasks)
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

            usleep(10000);

        } while (true);
    }
}