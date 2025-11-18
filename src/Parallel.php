<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Utilities\TaskAwaiter;
use Hibla\Promise\Interfaces\PromiseInterface;

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
        return TaskAwaiter::awaitAll($tasks, $timeoutSeconds, $maxConcurrency, $pollIntervalMs);
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
        return TaskAwaiter::awaitAllSettled($tasks, $timeoutSeconds, $maxConcurrency, $pollIntervalMs);
    }

    /**
     * Cancel multiple tasks that are taking too long (non-blocking)
     * 
     * @param array $taskIds Array of task IDs to check and cancel if running
     * @return PromiseInterface<array> Promise that resolves to cancellation results
     */
    public static function cancelTasks(array $taskIds): PromiseInterface
    {
        return async(function () use ($taskIds) {
            $results = [];
            foreach ($taskIds as $key => $taskId) {
                $results[$key] = await(Process::cancel($taskId));
            }
            return $results;
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
                return await(TaskAwaiter::awaitAll($taskIds, $timeoutSeconds, $maxConcurrency, $pollIntervalMs));
            } catch (\Throwable $e) {
                // Cancel all tasks that are still running
                $cancelled = 0;
                foreach ($taskIds as $taskId) {
                    if (Process::isRunning($taskId)) {
                        $result = await(Process::cancel($taskId));
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
     * Get information about all currently running tasks (non-blocking)
     * 
     * @return array Array of running tasks with their status
     */
    public static function getRunningTasks(): array
    {
        return Process::getCancellableTasks();
    }

    /**
     * Cancel all currently running tasks (non-blocking)
     * 
     * @return PromiseInterface<array> Promise that resolves to summary of cancellation results
     */
    public static function cancelAll(): PromiseInterface
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
     * Get optimal number of processes based on system resources
     * 
     * @param bool $printInfo Whether to print CPU and core information
     * @param int|null $maxLimit Optional maximum limit
     * @return array ['cpu' => int, 'io' => int] Recommended process counts
     */
    public static function optimalProcessCount(bool $printInfo = false, ?int $maxLimit = null): array
    {
        // Get CPU core count (fallback to 4 if unable to determine)
        $cores = 4;
        $detectionMethod = 'fallback';

        if (function_exists('shell_exec')) {
            if (PHP_OS_FAMILY === 'Windows') {
                $windowsCores = shell_exec('echo %NUMBER_OF_PROCESSORS% 2>nul');
                if ($windowsCores && trim($windowsCores)) {
                    $cores = max(1, (int)trim($windowsCores));
                    $detectionMethod = 'NUMBER_OF_PROCESSORS (Windows)';
                } else {
                    $wmic = shell_exec('wmic cpu get NumberOfCores /value 2>nul | findstr NumberOfCores');
                    if ($wmic && preg_match('/NumberOfCores=(\d+)/', $wmic, $matches)) {
                        $cores = max(1, (int)$matches[1]);
                        $detectionMethod = 'WMIC (Windows)';
                    }
                }
            } else {
                $linuxCores = shell_exec('nproc 2>/dev/null');
                if ($linuxCores && trim($linuxCores)) {
                    $cores = max(1, (int)trim($linuxCores));
                    $detectionMethod = 'nproc (Linux)';
                } else {
                    $bsdCores = shell_exec('sysctl -n hw.ncpu 2>/dev/null');
                    if ($bsdCores && trim($bsdCores)) {
                        $cores = max(1, (int)trim($bsdCores));
                        $detectionMethod = 'sysctl (macOS/BSD)';
                    }
                }
            }
        }

        // Calculate optimal processes for both task types
        $cpuOptimal = $cores; // 1x cores for CPU-bound tasks
        $ioOptimal = $cores * 2; // 2x cores for I/O-bound tasks

        // Apply maximum limit if specified
        if ($maxLimit !== null) {
            $cpuOptimal = min($cpuOptimal, $maxLimit);
            $ioOptimal = min($ioOptimal, $maxLimit);
        }

        if ($printInfo) {
            echo "=== System CPU Information ===\n";
            echo "Platform: " . PHP_OS_FAMILY . "\n";
            echo "CPU Cores: {$cores} (detected via: {$detectionMethod})\n";
            echo "CPU-bound Tasks: {$cpuOptimal} processes (1x cores)\n";
            echo "I/O-bound Tasks: {$ioOptimal} processes (2x cores)\n";
            if ($maxLimit !== null) {
                echo "Applied Limit: {$maxLimit}\n";
            }
            echo "==============================\n";
        }

        return [
            'cpu' => max(1, $cpuOptimal),
            'io' => max(1, $ioOptimal)
        ];
    }
}
