<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Handlers\BackgroundProcessExecutorHandler;
use Hibla\Parallel\Utilities\LazyTask;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

/**
 * Static process utility for monitoring and awaiting background tasks
 * Integrated with Hibla's async ecosystem
 */
class Process
{
    protected static ?BackgroundProcessExecutorHandler $handler = null;

    protected static function getHandler(): BackgroundProcessExecutorHandler
    {
        if (self::$handler === null) {
            self::$handler = new BackgroundProcessExecutorHandler;
        }
        return self::$handler;
    }

    /**
     * Spawn and Execute a background task and return a Promise<string> (task ID)
     * 
     * @return PromiseInterface<string> Promise resolving to unique task ID
     */
    public static function spawn(callable $callback, array $context = []): PromiseInterface
    {
        return async(
            fn() => self::getHandler()->executeBackground($callback, $context)
        );
    }

    /**
     * Create a lazy background task that only executes when awaited
     * 
     * @return string Lazy task ID
     */
    public static function lazy(callable $callback, array $context = []): string
    {
        return LazyTask::create($callback, $context);
    }

    /**
     * Cancel a running task by its task ID (non-blocking operation)
     * 
     * @param string $taskId The task ID to cancel
     * @return array Cancellation result
     */
    public static function cancel(string $taskId): array
    {
        if (LazyTask::isLazyId($taskId)) {
            $task = LazyTask::get($taskId);
            if (!$task) {
                return [
                    'success' => false,
                    'message' => 'Lazy task not found',
                    'task_id' => $taskId
                ];
            }

            if (!$task->isExecuted()) {
                return [
                    'success' => false,
                    'message' => 'Lazy task has not been executed yet',
                    'task_id' => $taskId
                ];
            }

            $realTaskId = $task->getRealTaskId();
            return self::getHandler()->cancelTask($realTaskId);
        }

        return self::getHandler()->cancelTask($taskId);
    }

    /**
     * Cancel multiple tasks at once
     */
    public static function cancelMultiple(array $taskIds): array
    {
        $results = [];
        foreach ($taskIds as $taskId) {
            $results[$taskId] = self::cancel($taskId);
        }
        return $results;
    }

    /**
     * Cancel all running tasks
     */
    public static function cancelAll(): array
    {
        return self::getHandler()->cancelAllRunningTasks();
    }

    /**
     * Check if a task process is still running (non-blocking)
     * 
     * @param string $taskId The task ID to check
     * @return bool True if the process is running
     */
    public static function isRunning(string $taskId): bool
    {
        if (LazyTask::isLazyId($taskId)) {
            $task = LazyTask::get($taskId);
            if (!$task || !$task->isExecuted()) {
                return false;
            }
            $taskId = $task->getRealTaskId();
        }

        return self::getHandler()->isTaskRunning($taskId);
    }

    /**
     * Get all cancellable (running/pending) tasks
     * 
     * @return array Array of cancellable tasks
     */
    public static function getCancellableTasks(): array
    {
        return self::getHandler()->getCancellableTasks();
    }

    /**
     * Wait for a task with ability to cancel on timeout (non-blocking)
     * 
     * @param string $taskId Task ID to await
     * @param int $timeoutSeconds Timeout in seconds
     * @param bool $cancelOnTimeout Whether to cancel the task if it times out
     * @return PromiseInterface<mixed> Promise that resolves to task result
     */
    public static function awaitOrCancel(string $taskId, int $timeoutSeconds = 60, bool $cancelOnTimeout = true): PromiseInterface
    {
        return async(function () use ($taskId, $timeoutSeconds, $cancelOnTimeout) {
            try {
                return await(self::await($taskId, $timeoutSeconds));
            } catch (\RuntimeException $e) {
                if ($cancelOnTimeout && str_contains($e->getMessage(), 'timed out')) {
                    $cancelResult = self::cancel($taskId);
                    throw new \RuntimeException(
                        $e->getMessage() . ' (Task cancelled: ' . ($cancelResult['success'] ? 'yes' : 'no') . ')',
                        0,
                        $e
                    );
                }
                throw $e;
            }
        });
    }

    /**
     * Monitor a task until completion or timeout (non-blocking)
     */
    public static function monitor(string $taskId, int $timeoutSeconds = 30, ?callable $progressCallback = null): PromiseInterface
    {
        return async(function () use ($taskId, $timeoutSeconds, $progressCallback) {
            $startTime = time();
            $lastStatus = null;
            $displayedOutput = false;

            while (true) {
                $status = self::getTaskStatus($taskId);

                if (isset($status['output']) && !empty($status['output']) && !$displayedOutput) {
                    echo $status['output'];
                    $displayedOutput = true;
                }

                if ($progressCallback && $status !== $lastStatus) {
                    $progressCallback($status);
                    $lastStatus = $status;
                }

                if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND', 'CANCELLED'])) {
                    if (isset($status['output']) && !empty($status['output']) && !$displayedOutput) {
                        echo $status['output'];
                    }
                    return $status;
                }

                if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                    return array_merge($status, [
                        'timeout' => true,
                        'message' => $status['message'] . ' (monitoring timeout reached)'
                    ]);
                }

                await(delay(0.01)); // 10ms delay
            }
        });
    }

    /**
     * Wait for a task to complete and return its result (non-blocking)
     */
    public static function await(string $taskId, int $timeoutSeconds = 60): PromiseInterface
    {
        return async(function () use ($taskId, $timeoutSeconds) {
            if (LazyTask::isLazyId($taskId)) {
                $task = LazyTask::get($taskId);
                if (!$task) {
                    throw new \RuntimeException("Lazy task not found: {$taskId}");
                }

                $realTaskId = await($task->execute());
                return await(self::await($realTaskId, $timeoutSeconds));
            }

            $finalStatus = await(self::monitor($taskId, $timeoutSeconds));

            if ($finalStatus['status'] === 'COMPLETED') {
                return $finalStatus['result'] ?? null;
            }

            if ($finalStatus['status'] === 'CANCELLED') {
                throw new \RuntimeException("Task {$taskId} was cancelled");
            }

            if (isset($finalStatus['timeout']) && $finalStatus['timeout']) {
                throw new \RuntimeException("Task {$taskId} timed out after {$timeoutSeconds} seconds");
            }

            if ($finalStatus['status'] === 'ERROR') {
                $errorMsg = $finalStatus['error_message'] ?? $finalStatus['message'];
                throw new \RuntimeException("Task {$taskId} failed: {$errorMsg}");
            }

            throw new \RuntimeException("Task {$taskId} ended with unexpected status: " . $finalStatus['status']);
        });
    }

    /**
     * Get task status, handling lazy tasks
     */
    public static function getTaskStatus(string $taskId): array
    {
        if (LazyTask::isLazyId($taskId)) {
            $task = LazyTask::get($taskId);
            if (!$task) {
                return [
                    'task_id' => $taskId,
                    'status' => 'NOT_FOUND',
                    'message' => 'Lazy task not found'
                ];
            }

            if (!$task->isExecuted()) {
                return [
                    'task_id' => $taskId,
                    'status' => 'LAZY_PENDING',
                    'message' => 'Lazy task not yet executed'
                ];
            }

            return self::getTaskStatus($task->getRealTaskId());
        }

        return self::getHandler()->getTaskStatus($taskId);
    }

    /**
     * Get status of all background tasks
     */
    public static function getAllTasksStatus(): array
    {
        return self::getHandler()->getAllTasksStatus();
    }

    /**
     * Get summary statistics of background tasks
     */
    public static function getTasksSummary(): array
    {
        return self::getHandler()->getTasksSummary();
    }

    /**
     * Get recent background task logs
     */
    public static function getRecentLogs(int $limit = 100): array
    {
        return self::getHandler()->getRecentLogs($limit);
    }

    /**
     * Clean up old background task files and logs
     */
    public static function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return self::getHandler()->cleanupOldTasks($maxAgeHours);
    }

    /**
     * Get lazy task context without executing
     */
    public static function getLazyTaskContext(string $lazyTaskId): ?array
    {
        $task = LazyTask::get($lazyTaskId);
        return $task ? $task->getContext() : null;
    }

    /**
     * Update lazy task context before execution
     */
    public static function setLazyTaskContext(string $lazyTaskId, array $context): bool
    {
        $task = LazyTask::get($lazyTaskId);
        if ($task) {
            $task->setContext($context);
            return true;
        }
        return false;
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
        $cores = 1;
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

        $cpuOptimal = $cores;
        $ioOptimal = $cores * 2;

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
