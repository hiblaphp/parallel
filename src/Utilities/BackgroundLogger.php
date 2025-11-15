<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Config\ConfigLoader;

/**
 * Handles logging for background processes
 */
class BackgroundLogger
{
    private ConfigLoader $config;
    private string $logDir;
    private ?string $logFile;
    private bool $enableDetailedLogging;

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
     * Log task-specific events
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
     * Log system events
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
     * Get recent log entries for monitoring
     */
    public function getRecentLogs(int $limit = 100): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $logs = [];
        $recentLines = array_slice($lines, -$limit);

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
     * Get log file path
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Get log directory path
     */
    public function getLogDirectory(): string
    {
        return $this->logDir;
    }

    /**
     * Check if detailed logging is enabled
     */
    public function isDetailedLoggingEnabled(): bool
    {
        return $this->enableDetailedLogging;
    }

    /**
     * Enable or disable detailed logging
     */
    public function setDetailedLogging(bool $enabled): void
    {
        $this->enableDetailedLogging = $enabled;

        if ($enabled) {
            $this->logEvent('INFO', 'Detailed logging enabled');
        }
    }

    /**
     * Ensure all necessary directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Initialize logging system
     */
    private function initializeLogging(): void
    {
        if ($this->enableDetailedLogging) {
            $this->logEvent('INFO', 'Background process executor initialized - PHP ' . PHP_VERSION . ' on ' . PHP_OS_FAMILY);
        }
    }
}