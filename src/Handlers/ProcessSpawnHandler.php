<?php

declare(strict_types=1);

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Process;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Rcalicdan\ConfigLoader\Config;
use Rcalicdan\Serializer\CallbackSerializationManager;

/**
 * Handles spawning and managing parallel worker processes.
 *
 * This class is responsible for creating both streamed and fire-and-forget
 * background processes, setting up their communication channels, and preparing
 * task payloads for execution.
 */
class ProcessSpawnHandler
{
    private string|int $defaultMemoryLimit;

    public function __construct(
        private SystemUtilities $systemUtils,
        private BackgroundLogger $logger
    ) {
        $requiredFunctions = PHP_OS_FAMILY !== 'Windows'
            ? ['proc_open', 'exec', 'shell_exec', 'posix_kill']
            : ['proc_open', 'exec', 'shell_exec'];


        $missingFunctions = array_filter($requiredFunctions, static function (string $function): bool {
            // @phpstan-ignore-next-line the functions are check at runtime
            return ! function_exists($function);
        });

        if (\count($missingFunctions) > 0) {
            throw new \RuntimeException(
                \sprintf(
                    'The following required functions are disabled on this server: "%s". ' .
                        'Hibla Parallel requires these functions to spawn and manage processes. ' .
                        'Please check the "disable_functions" directive in your php.ini file.',
                    implode('", "', $missingFunctions)
                )
            );
        }

        $configMemoryLimit = Config::loadFromRoot('hibla_parallel', 'background_process.memory_limit', '512M');
        $this->defaultMemoryLimit = (\is_string($configMemoryLimit) || \is_int($configMemoryLimit)) ? $configMemoryLimit : '512M';
    }

    /**
     * Spawns a streamed task process with bidirectional communication.
     *
     * Creates a new process that maintains open pipes for stdin, stdout, and stderr,
     * allowing real-time streaming of output and status updates.
     *
     * @template TResult
     * @param string $taskId Unique identifier for the task
     * @param callable(): TResult $callback The callback function to execute in the worker
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return Process<TResult> The spawned process instance with communication streams
     * @throws \RuntimeException If process spawning fails
     */
    public function spawnStreamedTask(
        string $taskId,
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled,
        int $timeoutSeconds = 60,
        string $sourceLocation = 'unknown'
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

        if (! \is_resource($processResource)) {
            $error = error_get_last();
            $errorMessage = $error['message'] ?? 'Unknown error';

            throw new \RuntimeException('Failed to spawn process. OS Error: ' . $errorMessage);
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
                $frameworkInfo,
                $serializationManager,
                $loggingEnabled,
                $timeoutSeconds
            );
            $stdin->writeAsync($payload . PHP_EOL);
        } catch (\Throwable $e) {
            proc_terminate($processResource);
            proc_close($processResource);

            throw $e;
        }

        /** @var Process<TResult> */
        return new Process(
            $taskId,
            $pid,
            $processResource,
            $stdin,
            $stdout,
            $stderr,
            $statusFile,
            $loggingEnabled,
            $sourceLocation
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
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @param int $timeoutSeconds The maximum execution time in seconds. Use 0 for no limit.
     * @return BackgroundProcess The spawned background process instance
     * @throws \RuntimeException If process spawning fails
     */
    public function spawnBackgroundTask(
        string $taskId,
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled,
        int $timeoutSeconds = 600,
    ): BackgroundProcess {
        $this->validateTimeout($timeoutSeconds);

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

        if (! \is_resource($processResource)) {
            $error = error_get_last();
            $errorMessage = $error['message'] ?? 'Unknown error';

            throw new \RuntimeException('Failed to spawn fire-and-forget process. OS Error: ' . $errorMessage);
        }

        $status = proc_get_status($processResource);
        $pid = $status['pid'];

        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.json';

        try {
            $payload = $this->createTaskPayload(
                $taskId,
                $statusFile,
                $callback,
                $frameworkInfo,
                $serializationManager,
                $loggingEnabled,
                $timeoutSeconds
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
     * @param string $taskId Unique identifier for the task
     * @param string $statusFile Path to the status file for tracking
     * @param callable $callback The callback function to serialize
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param bool $loggingEnabled Whether logging is enabled for this task
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @return string JSON-encoded payload string
     * @throws \RuntimeException If serialization fails
     */
    private function createTaskPayload(
        string $taskId,
        string $statusFile,
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        bool $loggingEnabled,
        int $timeoutSeconds = 60
    ): string {
        $serializedCallback = $serializationManager->serializeCallback($callback);

        $serializedBootstrapCallback = null;
        $bootstrapCallback = $frameworkInfo['bootstrap_callback'] ?? null;
        if ($bootstrapCallback !== null && is_callable($bootstrapCallback)) {
            $serializedBootstrapCallback = $serializationManager->serializeCallback($bootstrapCallback);
        }

        /** @var array<string, mixed> $payloadData */
        $payloadData = [
            'task_id' => $taskId,
            'status_file' => $statusFile,
            'serialized_callback' => $serializedCallback,
            'autoload_path' => $this->systemUtils->findAutoloadPath(),
            'framework_bootstrap' => $frameworkInfo['bootstrap_file'] ?? null,
            'framework_bootstrap_callback' => $serializedBootstrapCallback,
            'logging_enabled' => $loggingEnabled,
            'timeout_seconds' => $timeoutSeconds,
            'memory_limit' => $this->defaultMemoryLimit,
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

    /**
     * Validates the timeout value.
     *
     * Ensures that the timeout is within a reasonable range (1-86400 seconds)
     * to prevent runaway processes.
     *
     * @param int $timeoutSeconds Timeout duration in seconds
     * @throws \InvalidArgumentException If the timeout is out of range
     */
    private function validateTimeout(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException(
                'Timeout must be at least 1 second. Use a reasonable timeout to prevent runaway processes. ' .
                    'If you need a very long timeout, use a high value like 3600 (1 hour) or 86400 (24 hours).'
            );
        }

        if ($timeoutSeconds > 86400) {
            throw new \InvalidArgumentException(
                'Timeout cannot exceed 86400 seconds (24 hours). ' .
                    'For tasks that need longer execution, consider breaking them into smaller chunks.'
            );
        }
    }
}
