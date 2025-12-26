<?php

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Utilities\BackgroundLogger;

/**
 * Handles task status persistence and file management.
 * 
 * This class is strictly responsible for reading and writing task metadata 
 * to the storage (JSON files). It does not control process execution.
 */
final readonly class TaskStatusHandler
{
    /**
     * @param string $logDir Directory path for storing task status files
     */
    public function __construct(private string $logDir) {}

    /**
     * Creates initial status file for a new task.
     *
     * Generates a JSON status file containing initial task metadata including
     * callback type, context size, and timestamps.
     *
     * @param string $taskId Unique identifier for the task
     * @param callable $callback The callback function to be executed
     * @param array<string, mixed> $context Contextual data for the task
     * @return void
     */
    public function createInitialStatus(string $taskId, callable $callback, array $context): void
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.json';

        /** @var array<string, mixed> $initialStatus */
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
     * Updates task status file with new state.
     *
     * Merges the provided status information with existing data and writes
     * the updated status to the task's JSON file.
     *
     * @param string $taskId Unique identifier for the task
     * @param string $status New status value (e.g., 'RUNNING', 'COMPLETED', 'ERROR')
     * @param string $message Status message description
     * @param array<string, mixed> $extra Additional metadata to include in the status
     * @return void
     */
    public function updateStatus(string $taskId, string $status, string $message, array $extra = []): void
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.json';

        /** @var array<string, mixed> $statusData */
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
     * Retrieves task status from file.
     *
     * Reads and parses the task's JSON status file. Returns a default status
     * structure if the file doesn't exist or is corrupted.
     *
     * @param string $taskId Unique identifier for the task
     * @return array<string, mixed> Task status data including status, message, and timestamps
     */
    public function getTaskStatus(string $taskId): array
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.json';

        if (!file_exists($statusFile)) {
            return [
                'task_id' => $taskId,
                'status' => 'NOT_FOUND',
                'message' => 'Task not found or status file missing',
                'timestamp' => null
            ];
        }

        $statusContent = file_get_contents($statusFile);

        if ($statusContent === false) {
            return [
                'task_id' => $taskId,
                'status' => 'UNREADABLE',
                'message' => 'Unable to read status file',
                'timestamp' => null
            ];
        }

        /** @var array<string, mixed>|null $status */
        $status = json_decode($statusContent, true);

        if ($status === null) {
            $fileModTime = filemtime($statusFile);
            $timestamp = $fileModTime !== false ? $fileModTime : time();

            return [
                'task_id' => $taskId,
                'status' => 'CORRUPTED',
                'message' => 'Status file corrupted',
                'timestamp' => $timestamp,
                'created_at' => date('Y-m-d H:i:s', $timestamp),
                'updated_at' => date('Y-m-d H:i:s', $timestamp)
            ];
        }

        if (!isset($status['file_created_at'])) {
            $fileCreateTime = filectime($statusFile);
            $status['file_created_at'] = $fileCreateTime !== false
                ? date('Y-m-d H:i:s', $fileCreateTime)
                : date('Y-m-d H:i:s');
        }

        if (!isset($status['file_modified_at'])) {
            $fileModTime = filemtime($statusFile);
            $status['file_modified_at'] = $fileModTime !== false
                ? date('Y-m-d H:i:s', $fileModTime)
                : date('Y-m-d H:i:s');
        }

        return $status;
    }

    /**
     * Gets all tasks status by scanning the log directory.
     *
     * Scans all JSON files in the log directory and returns their status
     * information sorted by timestamp in descending order.
     *
     * @return array<string, array<string, mixed>> Associative array of task statuses keyed by task ID
     */
    public function getAllTasksStatus(): array
    {
        /** @var array<string, array<string, mixed>> $tasks */
        $tasks = [];
        $pattern = $this->logDir . DIRECTORY_SEPARATOR . '*.json';

        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        foreach ($files as $statusFile) {
            $taskId = basename($statusFile, '.json');
            $tasks[$taskId] = $this->getTaskStatus($taskId);
        }

        uasort($tasks, function (array $a, array $b): int {
            $timestampA = $a['timestamp'] ?? 0;
            $timestampB = $b['timestamp'] ?? 0;

            return (int)$timestampB - (int)$timestampA;
        });

        return $tasks;
    }

    /**
     * Gets aggregated statistics from all log files.
     *
     * Analyzes all task status files to generate comprehensive statistics
     * including counts by status, execution times, and memory usage metrics.
     *
     * @return array<string, mixed> Summary statistics including totals, averages, and aggregated metrics
     */
    public function getTasksSummary(): array
    {
        $allTasks = $this->getAllTasksStatus();

        /** @var array<string, mixed> $summary */
        $summary = [
            'total_tasks' => \count($allTasks),
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'unknown' => 0,
            'oldest_task' => null,
            'newest_task' => null,
            'total_execution_time' => 0.0,
            'average_execution_time' => 0.0,
            'longest_execution_time' => 0.0,
            'shortest_execution_time' => null,
            'total_memory_usage' => 0,
            'average_memory_usage' => 0.0,
            'peak_memory_usage' => 0
        ];

        /** @var array<int, int> $timestamps */
        $timestamps = [];

        /** @var array<int, float> $executionTimes */
        $executionTimes = [];

        /** @var array<int, int> $memoryUsages */
        $memoryUsages = [];

        foreach ($allTasks as $task) {
            $taskStatus = $task['status'] ?? 'UNKNOWN';

            switch ($taskStatus) {
                case 'RUNNING':
                    $summary['running']++;
                    break;
                case 'COMPLETED':
                    $summary['completed']++;
                    if (isset($task['duration']) && is_numeric($task['duration']) && $task['duration'] > 0) {
                        $duration = (float)$task['duration'];
                        $executionTimes[] = $duration;
                        $summary['longest_execution_time'] = max((float)$summary['longest_execution_time'], $duration);
                        $summary['shortest_execution_time'] = $summary['shortest_execution_time'] === null
                            ? $duration
                            : min((float)$summary['shortest_execution_time'], $duration);
                    }
                    break;
                case 'ERROR':
                case 'FAILED':
                case 'SPAWN_ERROR':
                    $summary['failed']++;
                    break;
                case 'PENDING':
                case 'RECEIVED':
                    $summary['pending']++;
                    break;
                case 'CANCELLED':
                    $summary['cancelled']++;
                    break;
                default:
                    $summary['unknown']++;
            }

            if (isset($task['timestamp']) && is_numeric($task['timestamp']) && $task['timestamp'] > 0) {
                $timestamps[] = (int)$task['timestamp'];
            }

            if (isset($task['memory_usage']) && is_numeric($task['memory_usage']) && $task['memory_usage'] > 0) {
                $memoryUsage = (int)$task['memory_usage'];
                $memoryUsages[] = $memoryUsage;
                $summary['peak_memory_usage'] = max((int)$summary['peak_memory_usage'], $memoryUsage);
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
     * Cleans up old task logs and status files.
     *
     * Removes task status files and temporary task files older than the specified
     * age. Running tasks are preserved regardless of age.
     *
     * @param int $maxAgeHours Maximum age in hours before a file is eligible for cleanup
     * @param string $tempDir Directory containing temporary task files
     * @return int Number of files successfully deleted
     */
    public function cleanupOldTasks(int $maxAgeHours, string $tempDir): int
    {
        $cutoffTime = time() - ($maxAgeHours * 3600);
        $cleanedCount = 0;

        $statusFiles = glob($this->logDir . DIRECTORY_SEPARATOR . '*.json');

        if ($statusFiles === false) {
            $statusFiles = [];
        }

        foreach ($statusFiles as $file) {
            $fileModTime = filemtime($file);

            if ($fileModTime === false || $fileModTime >= $cutoffTime) {
                continue;
            }

            $content = @file_get_contents($file);

            if ($content !== false) {
                /** @var array<string, mixed>|null $status */
                $status = json_decode($content, true);

                if ($status !== null && isset($status['status']) && $status['status'] === 'RUNNING') {
                    continue;
                }
            }

            if (@unlink($file)) {
                $cleanedCount++;
            }
        }

        $taskFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'defer_*.php');

        if ($taskFiles === false) {
            $taskFiles = [];
        }

        foreach ($taskFiles as $file) {
            $fileModTime = filemtime($file);

            if ($fileModTime !== false && $fileModTime < $cutoffTime) {
                if (@unlink($file)) {
                    $cleanedCount++;
                }
            }
        }

        return $cleanedCount;
    }

    /**
     * Exports task data for external monitoring.
     *
     * Creates a comprehensive export containing task statuses, summary statistics,
     * system information, and health check data. Can be filtered by task IDs.
     *
     * @param array<int, string> $taskIds Optional array of task IDs to export (empty for all)
     * @param array<string, mixed> $systemStats System statistics to include in export
     * @return array<string, mixed> Exported data structure with tasks and metadata
     */
    public function exportTaskData(array $taskIds, array $systemStats): array
    {
        $allTasks = $this->getAllTasksStatus();

        if (!empty($taskIds)) {
            $allTasks = array_filter($allTasks, function (string $taskId) use ($taskIds): bool {
                return in_array($taskId, $taskIds, true);
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
     * Imports task data from external source.
     *
     * Restores task status files from an exported data structure. Each task's
     * status is written as a JSON file in the log directory.
     *
     * @param array<string, mixed> $data Exported data structure containing tasks
     * @param BackgroundLogger $logger Logger instance for recording import events
     * @return bool True if at least one task was successfully imported, false otherwise
     */
    public function importTaskData(array $data, BackgroundLogger $logger): bool
    {
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return false;
        }

        $imported = 0;

        /** @var array<string, array<string, mixed>> $tasks */
        $tasks = $data['tasks'];

        foreach ($tasks as $taskId => $taskData) {
            if (!is_string($taskId) || !is_array($taskData)) {
                continue;
            }

            $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.json';

            $jsonData = json_encode($taskData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($jsonData !== false && file_put_contents($statusFile, $jsonData) !== false) {
                $imported++;
            }
        }

        $logger->logEvent('INFO', "Imported {$imported} tasks from external data");
        return $imported > 0;
    }

    /**
     * Gets health check information regarding file access and system components.
     *
     * Performs health checks on log directory, temp directory, PHP binary,
     * log files, and serialization components. Returns a structured health
     * status with individual check results.
     *
     * @param \Hibla\Parallel\Utilities\SystemUtilities|null $systemUtils Optional system utilities for additional checks
     * @param BackgroundLogger|null $logger Optional logger for log file checks
     * @param \Hibla\Parallel\Serialization\CallbackSerializationManager|null $serializationManager Optional serialization manager for serializer checks
     * @return array<string, mixed> Health check results with overall status and individual component checks
     */
    public function getHealthCheck($systemUtils = null, $logger = null, $serializationManager = null): array
    {
        /** @var array<string, mixed> $health */
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'timestamp' => time()
        ];

        $health['checks']['log_directory'] = [
            'status' => is_writable($this->logDir) ? 'ok' : 'error',
            'path' => $this->logDir,
            'writable' => is_writable($this->logDir)
        ];

        if ($systemUtils !== null) {
            $tempDir = $systemUtils->getTempDirectory();
            $health['checks']['temp_directory'] = [
                'status' => is_writable($tempDir) ? 'ok' : 'error',
                'path' => $tempDir,
                'writable' => is_writable($tempDir)
            ];

            $phpBinary = $systemUtils->getPhpBinary();
            $health['checks']['php_binary'] = [
                'status' => is_executable($phpBinary) ? 'ok' : 'warning',
                'path' => $phpBinary,
                'executable' => is_executable($phpBinary)
            ];
        }

        if ($logger !== null) {
            $logFile = $logger->getLogFile();
            if ($logFile !== null) {
                $fileExists = file_exists($logFile);
                $fileSize = $fileExists ? filesize($logFile) : 0;

                $health['checks']['log_file'] = [
                    'status' => ($fileExists && is_writable($logFile)) ? 'ok' : 'warning',
                    'path' => $logFile,
                    'exists' => $fileExists,
                    'writable' => $fileExists ? is_writable($logFile) : null,
                    'size' => $fileSize !== false ? $fileSize : 0
                ];
            }
        }

        if ($serializationManager !== null) {
            $health['checks']['serialization'] = [
                'status' => 'ok',
                'available_serializers' => $serializationManager->getSerializerInfo()
            ];
        }

        /** @var array<string, mixed> $checks */
        $checks = $health['checks'];

        foreach ($checks as $check) {
            if (!is_array($check) || !isset($check['status'])) {
                continue;
            }

            if ($check['status'] === 'error') {
                $health['status'] = 'error';
                break;
            } elseif ($check['status'] === 'warning' && $health['status'] !== 'error') {
                $health['status'] = 'warning';
            }
        }

        return $health;
    }

    /**
     * Gets callable type string for logging metadata.
     *
     * Determines the type of callable (function, method, closure, or callable object)
     * for metadata and debugging purposes.
     *
     * @param callable $callback The callback to analyze
     * @return string Type identifier ('function', 'method', 'closure', or 'callable_object')
     */
    protected function getCallableType(callable $callback): string
    {
        if (\is_string($callback)) {
            return 'function';
        } elseif (\is_array($callback)) {
            return 'method';
        } elseif ($callback instanceof \Closure) {
            return 'closure';
        } else {
            return 'callable_object';
        }
    }
}
