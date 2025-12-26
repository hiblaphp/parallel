<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Config\ConfigLoader;

/**
 * Handles logging for background processes and task tracking.
 *
 * This class manages both detailed logging to files and status-only tracking
 * for background tasks. It provides methods for logging task events, system
 * events, and retrieving recent log entries for monitoring purposes.
 */
final class BackgroundLogger
{
    private ConfigLoader $config;

    private string $logDir;

    private ?string $logFile;

    private bool $enableDetailedLogging;

    /**
     * @param ConfigLoader $config Configuration loader instance
     * @param bool|null $enableDetailedLogging Optional override for detailed logging setting (null uses config)
     * @param string|null $customLogDir Optional custom log directory path (null uses config or default)
     */
    public function __construct(
        ConfigLoader $config,
        ?bool $enableDetailedLogging = null,
        ?string $customLogDir = null
    ) {
        $this->config = $config;
        $this->enableDetailedLogging = $enableDetailedLogging ?? $this->config->get('logging.enabled', true);
        
        if ($this->enableDetailedLogging) {
            $logDir = $customLogDir ?? $this->config->get('logging.directory');
            $this->logDir = $logDir ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_logs');
            $this->logFile = $this->logDir . DIRECTORY_SEPARATOR . 'background_tasks.log';
        } else {
            $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_status';
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
     * Logs a system-level event without task context.
     *
     * Writes a timestamped log entry for system-wide events that aren't specific
     * to any particular task. Uses 'SYSTEM' as the task ID placeholder.
     *
     * @param string $level Log level (e.g., 'INFO', 'ERROR', 'WARNING')
     * @param string $message Log message describing the event
     * @return void
     */
    public function logEvent(string $level, string $message): void
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

    /**
     * Retrieves recent log entries for monitoring purposes.
     *
     * Reads and parses the most recent log entries from the log file, extracting
     * structured information including timestamp, level, task ID, and message.
     * Returns entries in chronological order.
     *
     * @param int $limit Maximum number of recent entries to retrieve (default: 100)
     * @return array<int, array<string, string|null>> Array of parsed log entries with structured fields
     */
    public function getRecentLogs(int $limit = 100): array
    {
        if ($this->logFile === null || !file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        /** @var array<int, array<string, string|null>> $logs */
        $logs = [];
        $recentLines = \array_slice($lines, -$limit);

        foreach ($recentLines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[([^\]]+)\] \[([^\]]+)\] (.+)$/', $line, $matches)) {
                $logs[] = [
                    'timestamp' => $matches[1],
                    'level' => $matches[2],
                    'task_id' => $matches[3] !== 'SYSTEM' ? $matches[3] : null,
                    'message' => $matches[4],
                    'raw_line' => $line
                ];
            }
        }

        return $logs;
    }

    /**
     * Gets the path to the main log file.
     *
     * Returns the full path to the log file where detailed task and system
     * events are written. Returns null if detailed logging is disabled.
     *
     * @return string|null Path to the log file, or null if detailed logging is disabled
     */
    public function getLogFile(): ?string
    {
        return $this->logFile;
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
     * Sets the detailed logging state.
     *
     * Enables or disables detailed logging to files. When enabling, logs
     * an informational message about the state change.
     * 
     * @param bool $enabled True to enable detailed logging, false to disable
     * @return void
     */
    public function setDetailedLogging(bool $enabled): void
    {
        $this->enableDetailedLogging = $enabled;

        if ($enabled && $this->logFile !== null) {
            $this->logEvent('INFO', 'Detailed logging enabled');
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