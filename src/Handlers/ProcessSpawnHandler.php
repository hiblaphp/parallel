<?php

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Process;
use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;

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

    public function spawnStreamedTask(
        string $taskId,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled
    ): Process {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath('worker.php');

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes);

        if (!\is_resource($processResource)) {
            throw new \RuntimeException("Failed to spawn process.");
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdin = new PromiseWritableStream($pipes[0]);
        $stdout = new PromiseReadableStream($pipes[1]);
        $stderr = new PromiseReadableStream($pipes[2]);

        $status = proc_get_status($processResource);
        $pid = $status['pid'];
        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.json';

        try {
            $payload = $this->createTaskPayload($taskId, $statusFile, $callback, $context, $frameworkInfo, $serializationManager, $loggingEnabled);
            $stdin->writeAsync($payload . PHP_EOL);
        } catch (\Throwable $e) {
            proc_terminate($processResource);
            proc_close($processResource);
            throw $e;
        }

        return new Process($taskId, $pid, $processResource, $stdin, $stdout, $stderr, $statusFile, $loggingEnabled);
    }

    public function spawnFireAndForgetTask(
        string $taskId,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled
    ): BackgroundProcess {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath(scriptName: 'worker_background.php');

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        ];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes);

        if (!\is_resource($processResource)) {
            throw new \RuntimeException("Failed to spawn fire-and-forget process.");
        }

        $status = proc_get_status($processResource);
        $pid = $status['pid'];

        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.json';

        try {
            $payload = $this->createTaskPayload($taskId, $statusFile, $callback, $context, $frameworkInfo, $serializationManager, $loggingEnabled);

            fwrite($pipes[0], $payload . PHP_EOL);
            fflush($pipes[0]);
        } finally {
            fclose($pipes[0]);
        }

        return new BackgroundProcess($taskId, $pid, $statusFile, $loggingEnabled);
    }

    private function createTaskPayload(
        string $taskId,
        string $statusFile,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled
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
            'logging_enabled' => $loggingEnabled,
        ];

        return json_encode($payloadData, JSON_UNESCAPED_SLASHES);
    }

    private function getWorkerPath(string $scriptName): string
    {
        $possiblePaths = [];
        try {
            $reflector = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
            $possiblePaths[] = dirname($reflector->getFileName(), 2) . '/hiblaphp/parallel/src/' . $scriptName;
        } catch (\ReflectionException $e) {
        }

        $possiblePaths[] = dirname(__DIR__) . '/../' . $scriptName; 
        $possiblePaths[] = dirname(__DIR__) . '/' . $scriptName;   

        foreach ($possiblePaths as $path) {
            if (($real = realpath($path)) && is_readable($real)) return $real;
        }

        throw new \RuntimeException("Worker script '$scriptName' not found.");
    }
}
