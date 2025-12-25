<?php

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Process;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Serialization\SerializationException;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Handlers\TaskStatusHandler;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Parallel\Utilities\TaskRegistry;

/**
 * Main manager for background process execution.
 * Orchestrates stream-based process spawning, status tracking, and task lifecycle.
 */
class BackgroundProcessManager
{
    private static ?self $instance = null;

    private ConfigLoader $config;
    private CallbackSerializationManager $serializationManager;
    private ProcessSpawnHandler $processSpawnHandler;
    private TaskStatusHandler $taskStatusHandler;
    private BackgroundLogger $logger;
    private SystemUtilities $systemUtils;
    private TaskRegistry $taskRegistry;
    private array $frameworkInfo = [];

    /**
     * Get the global singleton instance of the manager.
     */
    public static function getGlobal(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(
        ?CallbackSerializationManager $serializationManager = null,
        ?bool $enableDetailedLogging = null,
        ?string $customLogDir = null
    ) {
        $this->config = ConfigLoader::getInstance();
        $this->serializationManager = $serializationManager ?: new CallbackSerializationManager();

        $this->systemUtils = new SystemUtilities($this->config);
        $this->logger = new BackgroundLogger($this->config, $enableDetailedLogging, $customLogDir);
        $this->taskStatusHandler = new TaskStatusHandler($this->logger->getLogDirectory());
        $this->processSpawnHandler = new ProcessSpawnHandler($this->config, $this->systemUtils, $this->logger);
        $this->taskRegistry = new TaskRegistry();

        $this->frameworkInfo = $this->systemUtils->detectFramework();
    }

    /**
     * Spawns a callback in a true background process and returns a Process object.
     *
     * @param callable $callback The task to execute.
     * @param array $context Context to pass to the task.
     * @return Process A stateful object representing the live child process.
     * @throws \RuntimeException If spawning fails.
     * @throws SerializationException If the task or context cannot be serialized.
     */
    public function spawnStreamedTask(callable $callback, array $context = []): Process
    {
        if ($this->isRunningInBackground()) {
            $nestingLevel = (int) (getenv('DEFER_NESTING_LEVEL') ?:
                $_ENV['DEFER_NESTING_LEVEL'] ??
                $_SERVER['DEFER_NESTING_LEVEL'] ?? 1);

            throw new \RuntimeException(
                "Cannot spawn a background process from within another background process. " .
                    "Current nesting level: {$nestingLevel}. " .
                    "This prevents fork bombs and resource exhaustion. " .
                    "If you need nested parallelism, structure your code differently."
            );
        }

        $this->validateSerialization($callback, $context);

        $taskId = $this->systemUtils->generateTaskId();

        $this->taskRegistry->registerTask($taskId, $callback, $context);
        $this->taskStatusHandler->createInitialStatus($taskId, $callback, $context);

        $loggingEnabled = $this->logger->isDetailedLoggingEnabled();

        try {
            $process = $this->processSpawnHandler->spawnStreamedTask(
                $taskId,
                $callback,
                $context,
                $this->frameworkInfo,
                $this->serializationManager,
                $loggingEnabled  
            );

            $this->logger->logTaskEvent($taskId, 'SPAWNED', "Streamed process spawned successfully with PID {$process->getPid()}");

            return $process;
        } catch (\Throwable $e) {
            $this->logger->logTaskEvent($taskId, 'ERROR', 'Failed to spawn streamed process: ' . $e->getMessage());
            $this->taskStatusHandler->updateStatus($taskId, 'SPAWN_ERROR', 'Failed to spawn process: ' . $e->getMessage());
            throw $e;
        }
    }

    private function isRunningInBackground(): bool
    {
        $isBackground = getenv('DEFER_BACKGROUND_PROCESS') === '1' ||
            (isset($_ENV['DEFER_BACKGROUND_PROCESS']) && $_ENV['DEFER_BACKGROUND_PROCESS'] === '1') ||
            (isset($_SERVER['DEFER_BACKGROUND_PROCESS']) && $_SERVER['DEFER_BACKGROUND_PROCESS'] === '1');

        if ($isBackground) {
            return true;
        }

        $nestingLevel = (int) (getenv('DEFER_NESTING_LEVEL') ?:
            $_ENV['DEFER_NESTING_LEVEL'] ??
            $_SERVER['DEFER_NESTING_LEVEL'] ?? 0);

        return $nestingLevel > 0;
    }


    private function validateSerialization(callable $callback, array $context): void
    {
        if (!$this->serializationManager->canSerializeCallback($callback)) {
            throw new SerializationException('The provided callback is not serializable for background execution.');
        }
        if (!empty($context) && !$this->serializationManager->canSerializeContext($context)) {
            throw new SerializationException('The provided context data is not serializable for background execution.');
        }
    }

    public function getTaskStatus(string $taskId): array
    {
        return $this->taskStatusHandler->getTaskStatus($taskId);
    }

    public function getAllTasksStatus(): array
    {
        return $this->taskStatusHandler->getAllTasksStatus();
    }

    public function getTasksSummary(): array
    {
        return $this->taskStatusHandler->getTasksSummary();
    }

    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->taskStatusHandler->cleanupOldTasks($maxAgeHours, $this->systemUtils->getTempDirectory());
    }

    public function getHealthCheck(): array
    {
        return $this->taskStatusHandler->getHealthCheck(
            $this->systemUtils,
            $this->logger,
            $this->serializationManager
        );
    }

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

    public function getRecentLogs(int $limit = 100): array
    {
        return $this->logger->getRecentLogs($limit);
    }
}
