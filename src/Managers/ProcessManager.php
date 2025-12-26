<?php

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Process;
use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Handlers\TaskStatusHandler;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Parallel\Utilities\TaskRegistry;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Serialization\SerializationException;

/**
 * Manages the lifecycle of parallel processes and background tasks.
 *
 * This class serves as the central coordinator for spawning, tracking, and managing
 * parallel tasks. It handles both streamed tasks (with real-time output) and
 * fire-and-forget background tasks. Implements a singleton pattern for global access.
 */
class ProcessManager
{
    /**
     * @var self|null Singleton instance of the ProcessManager
     */
    private static ?self $instance = null;

    private ConfigLoader $config;

    private ProcessSpawnHandler $spawnHandler;

    private TaskStatusHandler $statusHandler;

    private BackgroundLogger $logger;

    private SystemUtilities $systemUtils;

    private TaskRegistry $taskRegistry;

    private CallbackSerializationManager $serializer;

    private array $frameworkInfo;

    /**
     * Gets or creates the global singleton instance.
     *
     * Provides a single global instance of ProcessManager for consistent
     * task management across the application.
     *
     * @return self The singleton ProcessManager instance
     */
    public static function getGlobal(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructs a new ProcessManager instance.
     */
    public function __construct()
    {
        $this->config = ConfigLoader::getInstance();
        $this->serializer = new CallbackSerializationManager();
        $this->systemUtils = new SystemUtilities($this->config);
        $this->logger = new BackgroundLogger($this->config);
        $this->statusHandler = new TaskStatusHandler($this->logger->getLogDirectory());
        $this->spawnHandler = new ProcessSpawnHandler($this->config, $this->systemUtils, $this->logger);
        $this->taskRegistry = new TaskRegistry();
        $this->frameworkInfo = $this->systemUtils->detectFramework();
    }

    /**
     * Spawns a streamed task with real-time output communication.
     *
     * Creates a new process that maintains open pipes for stdin, stdout, and stderr,
     * allowing real-time streaming of output and status updates. The task is
     * registered, validated, and tracked throughout its lifecycle.
     *
     * @param callable $callback The callback function to execute in the worker process
     * @param array<string, mixed> $context Contextual data to pass to the callback
     * @return Process The spawned process instance with communication streams
     * @throws \RuntimeException If task nesting is detected or validation fails
     * @throws SerializationException If the callback cannot be serialized
     */
    public function spawnStreamedTask(callable $callback, array $context = []): Process
    {
        $this->validate($callback);
        $taskId = $this->systemUtils->generateTaskId();
        
        $this->taskRegistry->registerTask($taskId, $callback, $context);
        $this->statusHandler->createInitialStatus($taskId, $callback, $context);
        
        $logging = $this->logger->isDetailedLoggingEnabled();

        $process = $this->spawnHandler->spawnStreamedTask(
            $taskId,
            $callback,
            $context,
            $this->frameworkInfo,
            $this->serializer,
            $logging
        );
        
        $this->logger->logTaskEvent($taskId, 'SPAWNED', "Streamed task PID: " . $process->getPid());
        return $process;
    }

    /**
     * Spawns a fire-and-forget background task.
     *
     * Creates a detached background process that runs independently without
     * maintaining communication channels. Suitable for tasks that don't require
     * real-time output monitoring. Task registration and logging are conditional
     * based on logging configuration.
     *
     * @param callable $callback The callback function to execute in the worker process
     * @param array<string, mixed> $context Contextual data to pass to the callback
     * @return BackgroundProcess The spawned background process instance
     * @throws \RuntimeException If task nesting is detected or validation fails
     * @throws SerializationException If the callback cannot be serialized
     */
    public function spawnFireAndForgetTask(callable $callback, array $context = []): BackgroundProcess
    {
        $this->validate($callback);
        $taskId = $this->systemUtils->generateTaskId();
        $logging = $this->logger->isDetailedLoggingEnabled();

        if ($logging) {
            $this->taskRegistry->registerTask($taskId, $callback, $context);
            $this->statusHandler->createInitialStatus($taskId, $callback, $context);
        }

        $process = $this->spawnHandler->spawnFireAndForgetTask(
            $taskId,
            $callback,
            $context,
            $this->frameworkInfo,
            $this->serializer,
            $logging
        );

        if ($logging) {
            $this->logger->logTaskEvent($taskId, 'SPAWNED_FF', "Fire&Forget PID: " . $process->getPid());
        }

        return $process;
    }

    /**
     * @param callable $callback The callback to validate
     * @param array<string, mixed> $context The context to validate
     * @return void
     * @throws \RuntimeException If task nesting is detected
     * @throws SerializationException If the callback cannot be serialized
     */
    private function validate(callable $callback): void
    {
        if ($this->isRunningInBackground()) {
            throw new \RuntimeException("Nesting parallel tasks is not allowed.");
        }
        if (!$this->serializer->canSerializeCallback($callback)) {
            throw new SerializationException("Callback not serializable.");
        }
    }

    /**
     * @return bool True if running in a background worker process, false otherwise
     */
    private function isRunningInBackground(): bool
    {
        $nestingLevel = getenv('DEFER_NESTING_LEVEL');
        return (int)($nestingLevel !== false ? $nestingLevel : 0) > 0;
    }
}