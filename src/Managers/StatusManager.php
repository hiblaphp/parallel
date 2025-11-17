<?php

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Utilities\BackgroundLogger;

/**
 * Manages task status tracking and monitoring
 */
class StatusManager
{
    protected string $logDir;

    public function __construct(string $logDir)
    {
        $this->logDir = $logDir;
    }

    /**
     * Create initial status for a task
     */
    public function createInitialStatus(string $taskId, callable $callback, array $context): void
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

        $initialStatus = [
            'task_id' => $taskId,
            'status' => 'PENDING',
            'message' => 'Task created and queued for execution',
            'timestamp' => time(),
            'duration' => null,
            'memory_usage' => null,
            'memory_peak' => null,
            'pid' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'callback_type' => $this->getCallableType($callback),
            'context_size' => count($context)
        ];

        file_put_contents($statusFile, json_encode($initialStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Update task status
     */
    public function updateStatus(string $taskId, string $status, string $message, array $extra = []): void
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

        $statusData = array_merge([
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'timestamp' => time(),
            'updated_at' => date('Y-m-d H:i:s')
        ], $extra);

        file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Get task status
     */
    public function getTaskStatus(string $taskId): array
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

        if (!file_exists($statusFile)) {
            return [
                'task_id' => $taskId,
                'status' => 'NOT_FOUND',
                'message' => 'Task not found or status file missing',
                'timestamp' => null
            ];
        }

        $statusContent = file_get_contents($statusFile);
        $status = json_decode($statusContent, true);

        if ($status === null) {
            return [
                'task_id' => $taskId,
                'status' => 'CORRUPTED',
                'message' => 'Status file corrupted',
                'timestamp' => filemtime($statusFile),
                'created_at' => date('Y-m-d H:i:s', filemtime($statusFile)),
                'updated_at' => date('Y-m-d H:i:s', filemtime($statusFile))
            ];
        }

        // Add file timestamps if missing
        if (!isset($status['file_created_at'])) {
            $status['file_created_at'] = date('Y-m-d H:i:s', filectime($statusFile));
        }
        if (!isset($status['file_modified_at'])) {
            $status['file_modified_at'] = date('Y-m-d H:i:s', filemtime($statusFile));
        }

        return $status;
    }

    /**
     * Get all tasks status
     */
    public function getAllTasksStatus(): array
    {
        $tasks = [];
        $pattern = $this->logDir . DIRECTORY_SEPARATOR . '*.status';

        foreach (glob($pattern) as $statusFile) {
            $taskId = basename($statusFile, '.status');
            $tasks[$taskId] = $this->getTaskStatus($taskId);
        }

        // Sort by creation time (newest first)
        uasort($tasks, function ($a, $b) {
            return ($b['timestamp'] ?? 0) - ($a['timestamp'] ?? 0);
        });

        return $tasks;
    }

    /**
     * Get tasks summary with statistics
     */
    public function getTasksSummary(): array
    {
        $allTasks = $this->getAllTasksStatus();
        $summary = [
            'total_tasks' => count($allTasks),
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'unknown' => 0,
            'oldest_task' => null,
            'newest_task' => null,
            'total_execution_time' => 0,
            'average_execution_time' => 0,
            'longest_execution_time' => 0,
            'shortest_execution_time' => null,
            'total_memory_usage' => 0,
            'average_memory_usage' => 0,
            'peak_memory_usage' => 0
        ];

        $timestamps = [];
        $executionTimes = [];
        $memoryUsages = [];

        foreach ($allTasks as $task) {
            switch ($task['status']) {
                case 'RUNNING':
                    $summary['running']++;
                    break;
                case 'COMPLETED':
                    $summary['completed']++;
                    if ($task['duration']) {
                        $executionTimes[] = $task['duration'];
                        $summary['longest_execution_time'] = max($summary['longest_execution_time'], $task['duration']);
                        $summary['shortest_execution_time'] = $summary['shortest_execution_time'] === null
                            ? $task['duration']
                            : min($summary['shortest_execution_time'], $task['duration']);
                    }
                    break;
                case 'ERROR':
                case 'FAILED':
                case 'SPAWN_ERROR':
                    $summary['failed']++;
                    break;
                case 'PENDING':
                    $summary['pending']++;
                    break;
                default:
                    $summary['unknown']++;
            }

            if ($task['timestamp']) {
                $timestamps[] = $task['timestamp'];
            }

            if (isset($task['memory_usage']) && $task['memory_usage']) {
                $memoryUsages[] = $task['memory_usage'];
                $summary['peak_memory_usage'] = max($summary['peak_memory_usage'], $task['memory_usage']);
            }
        }

        if (!empty($timestamps)) {
            $summary['oldest_task'] = date('Y-m-d H:i:s', min($timestamps));
            $summary['newest_task'] = date('Y-m-d H:i:s', max($timestamps));
        }

        if (!empty($executionTimes)) {
            $summary['total_execution_time'] = array_sum($executionTimes);
            $summary['average_execution_time'] = $summary['total_execution_time'] / count($executionTimes);
        }

        if (!empty($memoryUsages)) {
            $summary['total_memory_usage'] = array_sum($memoryUsages);
            $summary['average_memory_usage'] = $summary['total_memory_usage'] / count($memoryUsages);
        }

        return $summary;
    }

    /**
     * Clean up old task logs and status files
     */
    public function cleanupOldTasks(int $maxAgeHours, string $tempDir): int
    {
        $cutoffTime = time() - ($maxAgeHours * 3600);
        $cleanedCount = 0;

        // Clean up status files
        $statusFiles = glob($this->logDir . DIRECTORY_SEPARATOR . '*.status');
        foreach ($statusFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                // Check if task is still running before cleanup
                $status = json_decode(file_get_contents($file), true);
                if ($status && $status['status'] === 'RUNNING') {
                    continue;
                }

                if (unlink($file)) {
                    $cleanedCount++;
                }
            }
        }

        // Clean up task files
        $taskFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'defer_*.php');
        foreach ($taskFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }

    /**
     * Export task data for external monitoring
     */
    public function exportTaskData(array $taskIds, array $systemStats): array
    {
        $allTasks = $this->getAllTasksStatus();

        if (!empty($taskIds)) {
            $allTasks = array_filter($allTasks, function ($taskId) use ($taskIds) {
                return in_array($taskId, $taskIds);
            }, ARRAY_FILTER_USE_KEY);
        }

        return [
            'export_timestamp' => time(),
            'export_date' => date('Y-m-d H:i:s'),
            'summary' => $this->getTasksSummary(),
            'tasks' => $allTasks,
            'system_info' => $systemStats,
            'health_check' => $this->getHealthCheck(null, null, null)
        ];
    }

    /**
     * Import task data
     */
    public function importTaskData(array $data, BackgroundLogger $logger): bool
    {
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return false;
        }

        $imported = 0;
        foreach ($data['tasks'] as $taskId => $taskData) {
            $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.status';

            if (file_put_contents($statusFile, json_encode($taskData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
                $imported++;
            }
        }

        $logger->logEvent('INFO', "Imported {$imported} tasks from external data");
        return $imported > 0;
    }

    /**
     * Get health check information
     */
    public function getHealthCheck($systemUtils = null, $logger = null, $serializationManager = null): array
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => time()
        ];

        // Check log directory
        $health['checks']['log_directory'] = [
            'status' => is_writable($this->logDir) ? 'ok' : 'error',
            'path' => $this->logDir,
            'writable' => is_writable($this->logDir)
        ];

        if ($systemUtils) {
            // Check temp directory
            $tempDir = $systemUtils->getTempDirectory();
            $health['checks']['temp_directory'] = [
                'status' => is_writable($tempDir) ? 'ok' : 'error',
                'path' => $tempDir,
                'writable' => is_writable($tempDir)
            ];

            // Check PHP binary
            $phpBinary = $systemUtils->getPhpBinary();
            $health['checks']['php_binary'] = [
                'status' => is_executable($phpBinary) ? 'ok' : 'warning',
                'path' => $phpBinary,
                'executable' => is_executable($phpBinary)
            ];
        }

        if ($logger) {
            // Check log file
            $logFile = $logger->getLogFile();
            $health['checks']['log_file'] = [
                'status' => (file_exists($logFile) && is_writable($logFile)) ? 'ok' : 'warning',
                'path' => $logFile,
                'exists' => file_exists($logFile),
                'writable' => file_exists($logFile) ? is_writable($logFile) : null,
                'size' => file_exists($logFile) ? filesize($logFile) : 0
            ];
        }

        if ($serializationManager) {
            // Check serialization manager
            $health['checks']['serialization'] = [
                'status' => 'ok',
                'available_serializers' => $serializationManager->getSerializerInfo()
            ];
        }

        // Overall health status
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'error') {
                $hasErrors = true;
                break;
            } elseif ($check['status'] === 'warning') {
                $hasWarnings = true;
            }
        }

        if ($hasErrors) {
            $health['status'] = 'error';
        } elseif ($hasWarnings) {
            $health['status'] = 'warning';
        }

        return $health;
    }

    /**
     * Get callable type for logging
     */
    protected function getCallableType(callable $callback): string
    {
        if (is_string($callback)) {
            return 'function';
        } elseif (is_array($callback)) {
            return 'method';
        } elseif ($callback instanceof \Closure) {
            return 'closure';
        } else {
            return 'callable_object';
        }
    }
}
