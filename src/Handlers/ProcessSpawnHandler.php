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

/**
 * Handles spawning and managing parallel worker processes.
 *
 * This class is responsible for creating both streamed and fire-and-forget
 * background processes, setting up their communication channels, and preparing
 * task payloads for execution.
 */
class ProcessSpawnHandler
{
    public function __construct(
        private ConfigLoader $config,
        private SystemUtilities $systemUtils,
        private BackgroundLogger $logger
    ) {}

    /**
     * Spawns a streamed task process with bidirectional communication.
     *
     * Creates a new process that maintains open pipes for stdin, stdout, and stderr,
     * allowing real-time streaming of output and status updates.
     *
     * @param string $taskId Unique identifier for the task
     * @param callable $callback The callback function to execute in the worker
     * @param array<string, mixed> $context Contextual data to pass to the callback
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @return Process The spawned process instance with communication streams
     * @throws \RuntimeException If process spawning fails
     */
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
            $payload = $this->createTaskPayload(
                $taskId,
                $statusFile,
                $callback,
                $context,
                $frameworkInfo,
                $serializationManager,
                $loggingEnabled
            );
            $stdin->writeAsync($payload . PHP_EOL);
        } catch (\Throwable $e) {
            proc_terminate($processResource);
            proc_close($processResource);
            throw $e;
        }

        return new Process(
            $taskId,
            $pid,
            $processResource,
            $stdin,
            $stdout,
            $stderr,
            $statusFile,
            $loggingEnabled
        );
    }

    /**
     * Spawns a fire-and-forget background task process.
     *
     * Creates a detached background process that runs independently without
     * maintaining communication channels. Suitable for tasks that don't require
     * real-time output monitoring.
     *
     * @param string $taskId Unique identifier for the task
     * @param callable $callback The callback function to execute in the worker
     * @param array<string, mixed> $context Contextual data to pass to the callback
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @return BackgroundProcess The spawned background process instance
     * @throws \RuntimeException If process spawning fails
     */
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
            $payload = $this->createTaskPayload(
                $taskId,
                $statusFile,
                $callback,
                $context,
                $frameworkInfo,
                $serializationManager,
                $loggingEnabled
            );

            fwrite($pipes[0], $payload . PHP_EOL);
            fflush($pipes[0]);
        } finally {
            fclose($pipes[0]);
        }

        return new BackgroundProcess($taskId, $pid, $statusFile, $loggingEnabled);
    }

    /**
     * Creates a JSON-encoded task payload for the worker process.
     *
     * Serializes the callback, context, and framework information into a JSON
     * payload that can be passed to the worker process via stdin.
     *
     * @param string $taskId Unique identifier for the task
     * @param string $statusFile Path to the status file for tracking
     * @param callable $callback The callback function to serialize
     * @param array<string, mixed> $context Contextual data to serialize
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @return string JSON-encoded payload string
     * @throws \RuntimeException If serialization fails
     */
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

        /** @var array<string, mixed> $payloadData */
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

        $json = json_encode($payloadData, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode task payload: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Resolves the full path to a worker script.
     *
     * Attempts to locate the worker script in various possible installation
     * locations, including Composer vendor directories and relative paths.
     *
     * @param string $scriptName Name of the worker script file (e.g., 'worker.php')
     * @return string Absolute path to the worker script
     * @throws \RuntimeException If the worker script cannot be found
     */
    private function getWorkerPath(string $scriptName): string
    {
        /** @var array<int, string> $possiblePaths */
        $possiblePaths = [];

        try {
            $reflector = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
            $fileName = $reflector->getFileName();

            if ($fileName !== false) {
                $possiblePaths[] = dirname($fileName, 2) . '/hiblaphp/parallel/src/' . $scriptName;
            }
        } catch (\ReflectionException $e) {
            // Composer autoloader not found, continue with other paths
        }

        $possiblePaths[] = dirname(__DIR__) . '/../' . $scriptName;
        $possiblePaths[] = dirname(__DIR__) . '/' . $scriptName;

        foreach ($possiblePaths as $path) {
            $realPath = realpath($path);
            if ($realPath !== false && is_readable($realPath)) {
                return $realPath;
            }
        }

        throw new \RuntimeException("Worker script '$scriptName' not found.");
    }
}
