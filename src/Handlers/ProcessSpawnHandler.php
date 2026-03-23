<?php

declare(strict_types=1);

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Exceptions\ParallelException;
use Hibla\Parallel\Exceptions\ProcessSpawnException;
use Hibla\Parallel\Exceptions\TaskPayloadException;
use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Parallel\Internals\PersistentProcess;
use Hibla\Parallel\Internals\Process;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Rcalicdan\ConfigLoader\Config;
use Rcalicdan\Serializer\CallbackSerializationManager;

/**
 * @internal
 *
 * Handles spawning and managing parallel worker processes.
 *
 * This class is responsible for creating both streamed and fire-and-forget
 * background processes, setting up their communication channels, and preparing
 * task payloads for execution.
 */
class ProcessSpawnHandler
{
    private string|int $defaultProcessMemoryLimit;

    private string|int $defaultBackgroundMemoryLimit;

    /** @var array<string, string> */
    private array $workerPathCache = [];

    public function __construct(
        private SystemUtilities $systemUtils,
    ) {
        $requiredFunctions = PHP_OS_FAMILY !== 'Windows'
            ? ['proc_open', 'exec', 'shell_exec']
            : ['proc_open', 'exec', 'shell_exec'];

        $missingFunctions = array_filter($requiredFunctions, static function (string $function): bool {
            // @phpstan-ignore-next-line the functions are checked at runtime
            return ! function_exists($function);
        });

        if (\count($missingFunctions) > 0) {
            throw new ProcessSpawnException(
                \sprintf(
                    'The following required functions are disabled on this environment: "%s". ' .
                        'Hibla Parallel requires these functions to spawn and manage processes. ' .
                        'Please check the "disable_functions" directive in your php.ini file.',
                    implode('", "', $missingFunctions)
                )
            );
        }

        $procMemLimit = Config::loadFromRoot('hibla_parallel', 'process.memory_limit', '512M');
        $this->defaultProcessMemoryLimit = (\is_string($procMemLimit) || \is_int($procMemLimit)) ? $procMemLimit : '512M';

        $bgMemLimit = Config::loadFromRoot('hibla_parallel', 'background_process.memory_limit', '512M');
        $this->defaultBackgroundMemoryLimit = (\is_string($bgMemLimit) || \is_int($bgMemLimit)) ? $bgMemLimit : '512M';
    }

    /**
     * Spawns a streamed task process with bidirectional communication.
     *
     * Creates a new process that maintains open socket pairs for stdin, stdout, and stderr,
     * allowing real-time streaming of output and status updates. Using socket descriptors
     * instead of anonymous pipes ensures true non-blocking I/O on all platforms including
     * Windows, where anonymous pipes do not support non-blocking mode.
     *
     * On Windows, bypass_shell is set to true so proc_open() spawns PHP directly
     * without a cmd.exe wrapper. This ensures proc_get_status()['pid'] and
     * proc_terminate() both target the actual PHP worker rather than a shell
     * wrapper process, enabling reliable and immediate process termination.
     *
     * @template TResult
     * @param callable(): TResult $callback The callback function to execute in the worker
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @param string $sourceLocation Source file and line triggering the process
     * @param string|null $memoryLimit Custom memory limit for this specific process
     * @param int $maxNestingLevel Maximum nesting level for this process
     * @return Process<TResult> The spawned process instance with communication streams
     * @throws \RuntimeException If process spawning fails
     */
    public function spawnStreamedTask(
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        int $timeoutSeconds = 60,
        string $sourceLocation = 'unknown',
        ?string $memoryLimit = null,
        int $maxNestingLevel = 5
    ): Process {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath('worker.php');

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg((string)$maxNestingLevel);

        // Use socket descriptors on Windows since anonymous pipes ignore
        // stream_set_blocking(false) at the kernel level, starving the event loop.
        // On Unix, anonymous pipes are used instead as they are ~15% faster for
        // small messages and properly support non-blocking mode.
        $descriptorSpec = PHP_OS_FAMILY === 'Windows'
            ? [0 => ['socket'], 1 => ['socket'], 2 => ['socket']]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes, null, null, $options);

        if (! \is_resource($processResource)) {
            $error = error_get_last();
            $errorMessage = $error['message'] ?? 'Unknown error';

            throw new ProcessSpawnException('Failed to spawn process. OS Error: ' . $errorMessage);
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdin = new PromiseWritableStream($pipes[0]);
        $stdout = new PromiseReadableStream($pipes[1]);
        $stderr = new PromiseReadableStream($pipes[2]);

        $status = proc_get_status($processResource);
        $pid = $status['pid'];

        try {
            $payload = $this->createTaskPayload(
                $callback,
                $frameworkInfo,
                $serializationManager,
                $timeoutSeconds,
                $memoryLimit ?? $this->defaultProcessMemoryLimit
            );
            $stdin->writeAsync($payload . PHP_EOL);
        } catch (\Throwable $e) {
            proc_terminate($processResource);
            proc_close($processResource);

            throw $e;
        }

        /** @var Process<TResult> */
        return new Process(
            $pid,
            $processResource,
            $stdin,
            $stdout,
            $stderr,
            $sourceLocation
        );
    }

    /**
     * Spawns a fire-and-forget background task process.
     *
     * Creates a detached background process that runs independently without
     * maintaining communication channels. Suitable for tasks that don't require
     * real-time output monitoring. Uses anonymous pipes for stdin since only a
     * single write is needed to deliver the payload — no non-blocking reads are
     * ever performed on these descriptors by the parent.
     *
     * @param callable $callback The callback function to execute in the worker
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param int $timeoutSeconds The maximum execution time in seconds. Use 0 for no limit.
     * @param string|null $memoryLimit Custom memory limit for this specific process
     * @param int $maxNestingLevel Maximum nesting level for this process
     * @return BackgroundProcess The spawned background process instance
     */
    public function spawnBackgroundTask(
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        int $timeoutSeconds = 600,
        ?string $memoryLimit = null,
        int $maxNestingLevel = 5
    ): BackgroundProcess {
        $this->validateTimeout($timeoutSeconds);

        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath(scriptName: 'worker_background.php');

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg((string)$maxNestingLevel);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
        ];

        // bypass_shell on Windows so the resource maps to the actual PHP worker,
        // not a cmd.exe wrapper — same reasoning as spawnStreamedTask.
        $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes, null, null, $options);

        if (! \is_resource($processResource)) {
            $error = error_get_last();
            $errorMessage = $error['message'] ?? 'Unknown error';

            throw new ProcessSpawnException('Failed to spawn fire-and-forget process. OS Error: ' . $errorMessage);
        }

        $status = proc_get_status($processResource);
        $pid = $status['pid'];

        try {
            $payload = $this->createTaskPayload(
                $callback,
                $frameworkInfo,
                $serializationManager,
                $timeoutSeconds,
                $memoryLimit ?? $this->defaultBackgroundMemoryLimit
            );

            fwrite($pipes[0], $payload . PHP_EOL);
            fflush($pipes[0]);
        } finally {
            fclose($pipes[0]);
        }

        return new BackgroundProcess($pid, $processResource);
    }

    /**
     * Creates a JSON-encoded task payload for the worker process.
     *
     * @param callable $callback The callback function to serialize
     * @param array<string, mixed> $frameworkInfo Framework bootstrap information
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @param string|int $memoryLimit Memory limit for this specific process
     * @return string JSON-encoded payload string
     */
    private function createTaskPayload(
        callable $callback,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        int $timeoutSeconds,
        string|int $memoryLimit
    ): string {
        try {
            $serializedCallback = $serializationManager->serializeCallback($callback);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($e instanceof \TypeError || str_contains($message, 'getCallableForm') || str_contains($message, 'serialize')) {
                throw new TaskPayloadException(
                    "Failed to serialize task payload: The closure likely captures an unserializable object (e.g., ProcessPool, Stream, or PDO). Ensure you are not passing active OS resources into the closure via 'use (...)'. Underlying error: " . $message
                );
            }

            throw new TaskPayloadException('Failed to serialize task payload: ' . $message);
        }

        $serializedBootstrapCallback = null;
        $bootstrapCallback = $frameworkInfo['bootstrap_callback'] ?? null;
        if ($bootstrapCallback !== null && is_callable($bootstrapCallback)) {
            $serializedBootstrapCallback = $serializationManager->serializeCallback($bootstrapCallback);
        }

        /** @var array<string, mixed> $payloadData */
        $payloadData = [
            'serialized_callback' => $serializedCallback,
            'autoload_path' => $this->systemUtils->findAutoloadPath(),
            'framework_bootstrap' => $frameworkInfo['bootstrap_file'] ?? null,
            'framework_bootstrap_callback' => $serializedBootstrapCallback,
            'timeout_seconds' => $timeoutSeconds,
            'memory_limit' => $memoryLimit,
        ];

        $json = json_encode($payloadData, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new TaskPayloadException('Failed to encode task payload: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Spawns a persistent worker process for long-running tasks.
     *
     * On Windows, bypass_shell is set to true for the same reason as
     * spawnStreamedTask — prevents cmd.exe wrapping so proc_terminate()
     * targets the actual PHP worker PID directly.
     *
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null} $frameworkInfo
     * @param CallbackSerializationManager $serializationManager Manager for serializing callbacks
     * @param string|null $memoryLimit Custom memory limit for this specific process
     * @param int $maxNestingLevel Maximum allowed function nesting level
     * @param int|null $maxExecutionsPerWorker Maximum number of tasks before worker retires. Null means unlimited.
     * @return PersistentProcess Instance of the spawned persistent process
     */
    public function spawnPersistentWorker(
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        ?string $memoryLimit = null,
        int $maxNestingLevel = 5,
        ?int $maxExecutionsPerWorker = null,
        ?int $timeoutSeconds = null
    ): PersistentProcess {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $workerScript = $this->getWorkerPath('worker_persistent.php');

        $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript) . ' ' . escapeshellarg((string)$maxNestingLevel);

        $descriptorSpec = PHP_OS_FAMILY === 'Windows'
            ? [0 => ['socket'], 1 => ['socket'], 2 => ['socket']]
            : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $options = PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : [];

        $pipes = [];
        $processResource = @proc_open($command, $descriptorSpec, $pipes, null, null, $options);

        if (! \is_resource($processResource)) {
            throw new ProcessSpawnException('Failed to spawn persistent worker process.');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdin = new PromiseWritableStream($pipes[0]);
        $stdout = new PromiseReadableStream($pipes[1]);
        $stderr = new PromiseReadableStream($pipes[2]);

        // Send the one-time boot payload
        $bootPayload = $this->createBootPayload(
            $frameworkInfo,
            $serializationManager,
            $memoryLimit ?? $this->defaultProcessMemoryLimit,
            $maxExecutionsPerWorker,
            $timeoutSeconds,
        );
        $stdin->writeAsync($bootPayload . PHP_EOL);

        $status = proc_get_status($processResource);

        return new PersistentProcess(
            $status['pid'],
            $processResource,
            $stdin,
            $stdout,
            $stderr
        );
    }

    /**
     * Creates a JSON payload for initializing a persistent worker process.
     *
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null} $frameworkInfo
     * @param CallbackSerializationManager $serializationManager
     * @param string|int $memoryLimit
     * @param int|null $maxExecutionsPerWorker Maximum tasks before worker retires. Null means unlimited.
     */
    private function createBootPayload(
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager,
        string|int $memoryLimit,
        ?int $maxExecutionsPerWorker = null,
        ?int $timeoutSeconds = null
    ): string {
        $serializedBootstrapCallback = null;
        if (isset($frameworkInfo['bootstrap_callback']) && is_callable($frameworkInfo['bootstrap_callback'])) {
            $serializedBootstrapCallback = $serializationManager->serializeCallback($frameworkInfo['bootstrap_callback']);
        }

        $payloadData = [
            'autoload_path' => $this->systemUtils->findAutoloadPath(),
            'framework_bootstrap' => $frameworkInfo['bootstrap_file'] ?? null,
            'framework_bootstrap_callback' => $serializedBootstrapCallback,
            'memory_limit' => $memoryLimit,
            'timeout_seconds' => $timeoutSeconds,
            'max_executions_per_worker' => $maxExecutionsPerWorker,
        ];

        $json = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new TaskPayloadException('Failed to encode boot payload.');
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
     */
    private function getWorkerPath(string $scriptName): string
    {
        if (isset($this->workerPathCache[$scriptName])) {
            return $this->workerPathCache[$scriptName];
        }

        $localPaths = [
            dirname(__DIR__) . '/' . $scriptName,
            dirname(__DIR__) . '/../' . $scriptName,
        ];

        foreach ($localPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $resolvedPath = realpath($path);
                if ($resolvedPath !== false) {
                    $this->workerPathCache[$scriptName] = $resolvedPath;

                    return $resolvedPath;
                }
            }
        }

        if (class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            try {
                $reflector = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
                $fileName = $reflector->getFileName();

                if ($fileName !== false) {
                    $vendorPath = dirname($fileName, 2) . '/hiblaphp/parallel/src/' . $scriptName;
                    if (file_exists($vendorPath) && is_readable($vendorPath)) {
                        $resolvedPath = realpath($vendorPath);
                        if ($resolvedPath !== false) {
                            $this->workerPathCache[$scriptName] = $resolvedPath;

                            return $resolvedPath;
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                // Continue if reflection fails
            }
        }

        throw new ParallelException("Worker script '$scriptName' not found.");
    }

    /**
     * Validates the timeout value.
     *
     * Ensures that the timeout is a non-negative number. A value of 0 is
     * treated as no limit, equivalent to set_time_limit(0) in the child process.
     *
     * @param int $timeoutSeconds Timeout duration in seconds
     * @throws \InvalidArgumentException If the timeout is negative
     */
    private function validateTimeout(int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Timeout cannot be a negative number.');
        }

        if ($timeoutSeconds === 0) {
            return;
        }
    }
}
