<?php

namespace Hibla\Parallel\Utilities;

use Rcalicdan\ConfigLoader\Config;

/**
 * Handles logging for background processes and task tracking.
 *
 * This class manages both detailed logging to files and status-only tracking
 * for background tasks. It provides methods for logging task events, system
 * events, and retrieving recent log entries for monitoring purposes.
 */
final class BackgroundLogger
{
    private string $logDir;

    private ?string $logFile;

    private bool $enableDetailedLogging;

    /**
     * @param bool|null $enableDetailedLogging Optional override for detailed logging setting (null uses config)
     * @param string|null $customLogDir Optional custom log directory path (null uses config or default)
     */
    public function __construct(
        ?bool $enableDetailedLogging = null,
        ?string $customLogDir = null
    ) {
        $this->enableDetailedLogging = $enableDetailedLogging ?? Config::loadFromRoot('hibla_parallel', 'logging.enabled', false);

        if ($this->enableDetailedLogging) {
            $temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_parallel_logs';
            $logDir = $customLogDir ?? Config::loadFromRoot('hibla_parallel', 'logging.directory', $temp_dir);
            $this->logDir = $logDir ?: $temp_dir;
            $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . 'background_tasks.log';
        } else {
            $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_parallel_logs';
            $this->logFile = null;
        }

        $this->ensureDirectories();
        $this->initializeLogging();
    }

    /**
     * Logs a task-specific event with task ID context.
     *
     * Writes a timestamped log entry associated with a specific task. The entry
     * includes the task ID for correlation and tracking. Only writes when detailed
     * logging is enabled.
     *
     * @param string $taskId Unique identifier for the task
     * @param string $level Log level (e.g., 'INFO', 'ERROR', 'WARNING', 'SPAWNED')
     * @param string $message Log message describing the event
     * @return void
     */
    public function logTaskEvent(string $taskId, string $level, string $message): void
    {
        if (!$this->enableDetailedLogging || $this->logFile === null) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] [{$taskId}] {$message}" . PHP_EOL;

        if (file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to log file: {$this->logFile}");
        }
    }

    /**
     * Gets the log directory path.
     *
     * Returns the directory path where log files and status files are stored.
     * This directory is used for both detailed logs and status-only tracking.
     *
     * @return string Path to the log/status directory
     */
    public function getLogDirectory(): string
    {
        return $this->logDir;
    }

    /**
     * Checks if detailed logging is currently enabled.
     *
     * When detailed logging is enabled, events are written to log files.
     * When disabled, only status tracking is performed without file I/O overhead.
     *
     * @return bool True if detailed logging is enabled, false otherwise
     */
    public function isDetailedLoggingEnabled(): bool
    {
        return $this->enableDetailedLogging;
    }

    /**
     * Logs a system-level event without task context.
     *
     * Writes a timestamped log entry for system-wide events that aren't specific
     * to any particular task. Uses 'SYSTEM' as the task ID placeholder.
     *
     * @param string $level Log level (e.g., 'INFO', 'ERROR', 'WARNING')
     * @param string $message Log message describing the event
     * @return void
     */
    private function logEvent(string $level, string $message): void
    {
        if (!$this->enableDetailedLogging || $this->logFile === null) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] [SYSTEM] {$message}" . PHP_EOL;

        if (file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to log file: {$this->logFile}");
        }
    }


    private function ensureDirectories(): void
    {
        if (!is_dir($this->logDir)) {
            $created = @mkdir($this->logDir, 0755, true);

            if (!$created && !is_dir($this->logDir)) {
                error_log("Failed to create log directory: {$this->logDir}");
            }
        }
    }

    private function initializeLogging(): void
    {
        if ($this->enableDetailedLogging) {
            $this->logEvent('INFO', 'Background process executor initialized - PHP ' . PHP_VERSION . ' on ' . PHP_OS_FAMILY);
        }
    }
}
