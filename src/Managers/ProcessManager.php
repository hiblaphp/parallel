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

class ProcessManager
{
    private static ?self $instance = null;
    private ConfigLoader $config;
    private ProcessSpawnHandler $spawnHandler;
    private TaskStatusHandler $statusHandler;
    private BackgroundLogger $logger;
    private SystemUtilities $systemUtils;
    private TaskRegistry $taskRegistry;
    private CallbackSerializationManager $serializer;
    private array $frameworkInfo;

    public static function getGlobal(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        $this->config = ConfigLoader::getInstance();
        $this->serializer = new CallbackSerializationManager();
        $this->systemUtils = new SystemUtilities($this->config);
        $this->logger = new BackgroundLogger($this->config);
        $this->statusHandler = new TaskStatusHandler($this->logger->getLogDirectory());
        $this->spawnHandler = new ProcessSpawnHandler($this->config, $this->systemUtils, $this->logger);
        $this->taskRegistry = new TaskRegistry();
        $this->frameworkInfo = $this->systemUtils->detectFramework();
    }

    public function spawnStreamedTask(callable $callback, array $context = []): Process
    {
        $this->validate($callback, $context);
        $taskId = $this->systemUtils->generateTaskId();
        
        $this->taskRegistry->registerTask($taskId, $callback, $context);
        $this->statusHandler->createInitialStatus($taskId, $callback, $context);
        
        $logging = $this->logger->isDetailedLoggingEnabled();

        $process = $this->spawnHandler->spawnStreamedTask(
            $taskId, $callback, $context, $this->frameworkInfo, $this->serializer, $logging
        );
        
        $this->logger->logTaskEvent($taskId, 'SPAWNED', "Streamed task PID: " . $process->getPid());
        return $process;
    }

    public function spawnFireAndForgetTask(callable $callback, array $context = []): BackgroundProcess
    {
        $this->validate($callback, $context);
        $taskId = $this->systemUtils->generateTaskId();
        $logging = $this->logger->isDetailedLoggingEnabled();

        if ($logging) {
            $this->taskRegistry->registerTask($taskId, $callback, $context);
            $this->statusHandler->createInitialStatus($taskId, $callback, $context);
        }

        $process = $this->spawnHandler->spawnFireAndForgetTask(
            $taskId, $callback, $context, $this->frameworkInfo, $this->serializer, $logging
        );

        if ($logging) {
            $this->logger->logTaskEvent($taskId, 'SPAWNED_FF', "Fire&Forget PID: " . $process->getPid());
        }

        return $process;
    }

    private function validate(callable $callback, array $context): void
    {
        if ($this->isRunningInBackground()) {
             throw new \RuntimeException("Nesting parallel tasks is not allowed.");
        }
        if (!$this->serializer->canSerializeCallback($callback)) {
            throw new SerializationException("Callback not serializable.");
        }
    }

    private function isRunningInBackground(): bool
    {
        return (int)(getenv('DEFER_NESTING_LEVEL') ?: 0) > 0;
    }
}