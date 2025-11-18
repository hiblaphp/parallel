<?php

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Serialization\SerializationException;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Utilities\TaskRegistry;
use Hibla\Parallel\Managers\StatusManager;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;

/**
 * Handles background process execution for deferred tasks
 */
class BackgroundProcessExecutorHandler
{
    private ConfigLoader $config;
    private CallbackSerializationManager $serializationManager;
    private ProcessManager $processManager;
    private TaskRegistry $taskRegistry;
    private StatusManager $statusManager;
    private BackgroundLogger $logger;
    private SystemUtilities $systemUtils;

    private array $frameworkInfo = [];

    public function __construct(
        ?CallbackSerializationManager $serializationManager = null,
        ?bool $enableDetailedLogging = null,
        ?string $customLogDir = null
    ) {
        $this->config = ConfigLoader::getInstance();
        $this->serializationManager = $serializationManager ?: new CallbackSerializationManager();

        $this->systemUtils = new SystemUtilities($this->config);
        $this->logger = new BackgroundLogger($this->config, $enableDetailedLogging, $customLogDir);
        $this->statusManager = new StatusManager($this->logger->getLogDirectory());
        $this->taskRegistry = new TaskRegistry();
        $this->processManager = new ProcessManager($this->config, $this->systemUtils, $this->logger);

        if ($this->config->get('bootstrap_framework', true)) {
            $this->frameworkInfo = $this->systemUtils->detectFramework();
        } else {
            $this->frameworkInfo = ['name' => 'none', 'bootstrap_file' => null, 'init_code' => ''];
        }

        $this->logger->logEvent('INFO', 'Detected framework: ' . ($this->frameworkInfo['name'] ?? 'none'));
    }

    /**
     * Execute callback in a true background process with comprehensive logging
     */
    public function execute(callable $callback, array $context = []): string
    {
        if ($this->isRunningInBackground()) {
            $this->logger->logEvent('WARNING', 'Blocked nested background process spawn attempt');
            throw new \RuntimeException(
                'Cannot spawn background process from within another background process. ' .
                    'This prevents fork bombs and resource exhaustion.'
            );
        }

        $this->validateSerialization($callback, $context);

        $taskId = $this->systemUtils->generateTaskId();

        $this->taskRegistry->registerTask($taskId, $callback, $context);
        $this->statusManager->createInitialStatus($taskId, $callback, $context);

        try {
            $this->processManager->spawnBackgroundTask(
                $taskId,
                $callback,
                $context,
                $this->frameworkInfo,
                $this->serializationManager
            );

            $this->logger->logTaskEvent($taskId, 'SPAWNED', 'Background process spawned successfully');
            return $taskId;
        } catch (\Throwable $e) {
            $this->logger->logTaskEvent($taskId, 'ERROR', 'Failed to spawn background process: ' . $e->getMessage());
            $this->statusManager->updateStatus($taskId, 'SPAWN_ERROR', 'Failed to spawn background process: ' . $e->getMessage(), [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * Check if currently running in a background process
     */
    private function isRunningInBackground(): bool
    {
        return getenv('DEFER_BACKGROUND_PROCESS') === '1' ||
            (isset($_ENV['DEFER_BACKGROUND_PROCESS']) && $_ENV['DEFER_BACKGROUND_PROCESS'] === '1');
    }

    /**
     * Check if callback and context can be serialized for background execution
     */
    public function canExecute(callable $callback, array $context = []): bool
    {
        return $this->serializationManager->canSerializeCallback($callback) &&
            (empty($context) || $this->serializationManager->canSerializeContext($context));
    }

    /**
     * Get the status of a background task
     */
    public function getTaskStatus(string $taskId): array
    {
        return $this->statusManager->getTaskStatus($taskId);
    }

    /**
     * Get status of all active background tasks
     */
    public function getAllTasksStatus(): array
    {
        return $this->statusManager->getAllTasksStatus();
    }

    /**
     * Monitor tasks and return summary statistics
     */
    public function getTasksSummary(): array
    {
        return $this->statusManager->getTasksSummary();
    }

    /**
     * Clean up old task logs and status files
     */
    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->statusManager->cleanupOldTasks($maxAgeHours, $this->systemUtils->getTempDirectory());
    }

    /**
     * Get recent log entries for monitoring
     */
    public function getRecentLogs(int $limit = 100): array
    {
        return $this->logger->getRecentLogs($limit);
    }

    /**
     * Test background execution capabilities
     */
    public function testCapabilities(bool $verbose = false): array
    {
        return $this->processManager->testCapabilities($verbose, $this->serializationManager);
    }

    /**
     * Get background execution statistics
     */
    public function getStats(): array
    {
        return [
            'temp_dir' => $this->systemUtils->getTempDirectory(),
            'log_dir' => $this->logger->getLogDirectory(),
            'php_binary' => $this->systemUtils->getPhpBinary(),
            'logging_enabled' => $this->logger->isDetailedLoggingEnabled(),
            'framework' => $this->frameworkInfo,
            'serialization' => [
                'available_serializers' => $this->serializationManager->getSerializerInfo(),
            ],
            'environment' => $this->systemUtils->getEnvironmentInfo(),
            'disk_usage' => $this->systemUtils->getDiskUsage(),
            'registry' => [
                'tracked_tasks' => $this->taskRegistry->getTaskCount(),
            ]
        ];
    }

    public function getTemporaryFileStats(): array
    {
        $tempDir = $this->systemUtils->getTempDirectory();
        $logDir = $this->logger->getLogDirectory();

        $taskFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'defer_*.php');
        $statusFiles = glob($logDir . DIRECTORY_SEPARATOR . '*.status');

        $stats = [
            'temp_files' => [
                'count' => count($taskFiles),
                'total_size' => 0,
                'oldest' => null,
                'newest' => null
            ],
            'status_files' => [
                'count' => count($statusFiles),
                'total_size' => 0,
                'oldest' => null,
                'newest' => null
            ]
        ];

        // Calculate sizes and ages
        $taskTimes = [];
        foreach ($taskFiles as $file) {
            $stats['temp_files']['total_size'] += filesize($file);
            $taskTimes[] = filemtime($file);
        }

        $statusTimes = [];
        foreach ($statusFiles as $file) {
            $stats['status_files']['total_size'] += filesize($file);
            $statusTimes[] = filemtime($file);
        }

        if (!empty($taskTimes)) {
            $stats['temp_files']['oldest'] = date('Y-m-d H:i:s', min($taskTimes));
            $stats['temp_files']['newest'] = date('Y-m-d H:i:s', max($taskTimes));
        }

        if (!empty($statusTimes)) {
            $stats['status_files']['oldest'] = date('Y-m-d H:i:s', min($statusTimes));
            $stats['status_files']['newest'] = date('Y-m-d H:i:s', max($statusTimes));
        }

        return $stats;
    }

    /**
     * Get health check information
     */
    public function getHealthCheck(): array
    {
        return $this->statusManager->getHealthCheck(
            $this->systemUtils,
            $this->logger,
            $this->serializationManager
        );
    }

    /**
     * Export task data for external monitoring systems
     */
    public function exportTaskData(array $taskIds = []): array
    {
        return $this->statusManager->exportTaskData($taskIds, $this->getStats());
    }

    /**
     * Import task data (for testing or migration)
     */
    public function importTaskData(array $data): bool
    {
        return $this->statusManager->importTaskData($data, $this->logger);
    }

    public function getLogFile(): string
    {
        return $this->logger->getLogFile();
    }

    public function getLogDirectory(): string
    {
        return $this->logger->getLogDirectory();
    }

    public function getTempDirectory(): string
    {
        return $this->systemUtils->getTempDirectory();
    }

    public function isDetailedLoggingEnabled(): bool
    {
        return $this->logger->isDetailedLoggingEnabled();
    }

    public function setDetailedLogging(bool $enabled): void
    {
        $this->logger->setDetailedLogging($enabled);
    }

    public function getTaskRegistry(): array
    {
        return $this->taskRegistry->getAllTasks();
    }

    public function getStatusManager(): StatusManager
    {
        return $this->statusManager;
    }

    public function clearCompletedTasks(int $maxAge = 3600): int
    {
        return $this->taskRegistry->clearCompletedTasks($maxAge, $this->statusManager);
    }

    public function cancelTask(string $taskId): array
    {
        return $this->statusManager->cancelTask($taskId);
    }

    public function isTaskRunning(string $taskId): bool
    {
        $status = $this->statusManager->getTaskStatus($taskId);

        if ($status['status'] !== 'RUNNING') {
            return false;
        }

        $pid = $status['pid'] ?? null;
        if (!$pid) {
            return false;
        }

        return $this->statusManager->isProcessRunning($pid);
    }

    public function getCancellableTasks(): array
    {
        return $this->statusManager->getCancellableTasks();
    }

    public function cancelMultipleTasks(array $taskIds): array
    {
        return $this->statusManager->cancelMultipleTasks($taskIds);
    }

    public function cancelAllRunningTasks(): array
    {
        return $this->statusManager->cancelAllRunningTasks();
    }

    private function validateSerialization(callable $callback, array $context): void
    {
        if (!$this->serializationManager->canSerializeCallback($callback)) {
            throw new SerializationException('Callback cannot be serialized for background execution');
        }

        if (!empty($context) && !$this->serializationManager->canSerializeContext($context)) {
            throw new SerializationException('Context cannot be serialized for background execution');
        }
    }
}
