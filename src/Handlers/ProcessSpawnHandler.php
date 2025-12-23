<?php

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Process;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Serialization\SerializationException;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;

/**
 * Handles the spawning of background processes using proc_open and stream-based IPC.
 */
class ProcessSpawnHandler
{
    private ConfigLoader $config;
    private SystemUtilities $systemUtils;
    private BackgroundLogger $logger;

    public function __construct(
        ConfigLoader $config,
        SystemUtilities $systemUtils,
        BackgroundLogger $logger
    ) {
        $this->config = $config;
        $this->systemUtils = $systemUtils;
        $this->logger = $logger;
    }

    /**
     * Spawns a background task using a persistent worker and stream-based IPC.
     */
    public function spawnStreamedTask(
        string $taskId,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): Process {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath();

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript);
        
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], 
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($processResource)) {
            throw new \RuntimeException("Failed to spawn background process using proc_open. Command: {$command}");
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdin = new PromiseWritableStream($pipes[0]);
        $stdout = new PromiseReadableStream($pipes[1]);
        $stderr = new PromiseReadableStream($pipes[2]);

        $status = proc_get_status($processResource);
        if (!$status || !$status['running']) {
            proc_close($processResource);
            throw new \RuntimeException("Process failed to start. PID: " . ($status['pid'] ?? 'N/A'));
        }
        $pid = $status['pid'];

        try {
            $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.status';
            $payload = $this->createTaskPayload($taskId, $statusFile, $callback, $context, $frameworkInfo, $serializationManager);
            $stdin->writeAsync($payload . PHP_EOL);
        } catch (\Throwable $e) {
            proc_terminate($processResource);
            proc_close($processResource);
            throw $e;
        }
        
        return new Process($taskId, $pid, $processResource, $stdin, $stdout, $stderr);
    }

    /**
     * Creates the JSON payload for the worker, including the status file path.
     */
    private function createTaskPayload(
        string $taskId,
        string $statusFile,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): string {
        $callbackCode = $serializationManager->serializeCallback($callback);
        $contextCode = $serializationManager->serializeContext($context);

        $payloadData = [
            'task_id' => $taskId,
            'status_file' => $statusFile,
            'callback_code' => $callbackCode,
            'context_code' => $contextCode,
            'autoload_path' => $this->systemUtils->findAutoloadPath(),
            'framework_bootstrap' => $frameworkInfo['bootstrap_file'] ?? null,
            'framework_init_code' => $frameworkInfo['init_code'] ?? '',
        ];
        
        $jsonPayload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            throw new SerializationException('Failed to JSON-encode the task payload: ' . json_last_error_msg());
        }
        
        return $jsonPayload;
    }
    
    /**
     * Finds the absolute path to the internal worker.php script.
     */
    private function getWorkerPath(): string
    {
        $workerPath = realpath(dirname(__DIR__) . '/worker.php');

        if ($workerPath === false || !is_readable($workerPath)) {
            $expectedPath = dirname(__DIR__) . '/worker.php';
            throw new \RuntimeException("Critical library file 'worker.php' is missing or not readable. Expected at: {$expectedPath}");
        }
        
        return $workerPath;
    }
    
    public function testCapabilities(bool $verbose, CallbackSerializationManager $serializationManager): array
    {
        // todo: implement testCapabilities
    }
}