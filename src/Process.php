<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Managers\BackgroundProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

class Process
{
    protected static ?BackgroundProcessManager $handler = null;

    private int $pid;
    private $processResource;
    private PromiseWritableStreamInterface $stdin;
    private PromiseReadableStreamInterface $stdout;
    private PromiseReadableStreamInterface $stderr;
    private string $taskId;
    private string $statusFilePath;
    private bool $loggingEnabled;

    /**
     * @internal This should only be constructed by the ProcessSpawnHandler.
     */
    public function __construct(
        string $taskId,
        int $pid,
        $processResource,
        PromiseWritableStreamInterface $stdin,
        PromiseReadableStreamInterface $stdout,
        PromiseReadableStreamInterface $stderr,
        string $statusFilePath,
        bool $loggingEnabled = true
    ) {
        $this->taskId = $taskId;
        $this->pid = $pid;
        $this->processResource = $processResource;
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->statusFilePath = $statusFilePath;
        $this->loggingEnabled = $loggingEnabled;
    }

    /**
     * Spawns a new process asynchronously.
     *
     * @param callable $callback The task function to run.
     * @param array $context Optional context to pass to the task.
     * @return PromiseInterface<Process> A promise that resolves with the Process instance.
     */
    public static function spawn(callable $callback, array $context = []): PromiseInterface
    {
        return async(fn() => self::getHandler()->spawnStreamedTask($callback, $context));
    }

    /**
     * Waits for this specific process to complete and returns its result.
     * This is now an instance method, making the flow more object-oriented.
     *
     * @param int $timeoutSeconds Timeout in seconds.
     * @return PromiseInterface<mixed> A promise that resolves with the task's result.
     */
    public function await(int $timeoutSeconds = 60): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $timeoutPromise = delay($timeoutSeconds);

            if (PHP_OS_FAMILY === 'Windows') {
                $resultPromise = $this->pollResultFromFile($timeoutSeconds);
            } else {
                $resultPromise = $this->readResultFromStream();
            }

            try {
                $result = await(Promise::race([$resultPromise, $timeoutPromise]));

                if ($result === null && $timeoutPromise->isFulfilled()) {
                    $this->cancel();
                    throw new \RuntimeException("Task {$this->taskId} timed out after {$timeoutSeconds} seconds.");
                }

                return $result;
            } catch (\Throwable $e) {
                $this->cancel();
                throw $e;
            } finally {
                $this->close();
                $this->cleanupIfNeeded();
            }
        });
    }

    /**
     * LINUX STRATEGY: Reads from STDOUT pipe.
     * Efficient, event-driven, instant.
     */
    private function readResultFromStream(): PromiseInterface
    {
        return async(function () {
            while (null !== ($line = await($this->stdout->readLineAsync()))) {
                $status = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                if (($status['status'] ?? '') === 'OUTPUT') {
                    echo $status['output'] ?? '';
                    continue;
                }

                if (($status['status'] ?? '') === 'COMPLETED') {
                    return $status['result'] ?? null;
                }

                if (($status['status'] ?? '') === 'ERROR') {
                    $errorMessage = $status['message'] ?? 'Unknown error';
                    throw new \RuntimeException("Task {$this->taskId} failed in child process: {$errorMessage}");
                }
            }

            throw new \RuntimeException("Process stream for task {$this->taskId} ended unexpectedly.");
        });
    }

    /**
     * WINDOWS STRATEGY: Polls the .status file.
     * Prevents blocking the Main Event Loop on Windows pipes.
     * 
     * @param int $timeoutSeconds Maximum time to wait for the result
     */
    private function pollResultFromFile(int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $startTime = microtime(true);
            $pollInterval = 0.01; // 10 milliseconds
            
            while ((microtime(true) - $startTime) < $timeoutSeconds) {
                if (!file_exists($this->statusFilePath)) {
                    if (!$this->isRunning()) {
                        throw new \RuntimeException("Task {$this->taskId} process died before creating status file.");
                    }
                    await(delay($pollInterval));
                    continue;
                }

                clearstatcache(true, $this->statusFilePath);

                $content = @file_get_contents($this->statusFilePath);
                if ($content === false || $content === '') {
                    await(delay($pollInterval));
                    continue;
                }

                $status = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    await(delay($pollInterval));
                    continue;
                }

                $currentStatus = $status['status'] ?? '';

                if ($currentStatus === 'COMPLETED') {
                    return $status['result'] ?? null;
                }

                if ($currentStatus === 'ERROR') {
                    $errorMessage = $status['message'] ?? 'Unknown error';
                    throw new \RuntimeException("Task {$this->taskId} failed: {$errorMessage}");
                }

                await(delay($pollInterval));
            }

            if (file_exists($this->statusFilePath)) {
                clearstatcache(true, $this->statusFilePath);
                $content = @file_get_contents($this->statusFilePath);
                if ($content !== false) {
                    $status = json_decode($content, true);
                    if ($status && ($status['status'] ?? '') === 'COMPLETED') {
                        return $status['result'] ?? null;
                    }
                }
            }

            throw new \RuntimeException("Task {$this->taskId} polling timed out after {$timeoutSeconds} seconds.");
        });
    }

    public function isRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$this->pid}\" 2>nul";
            $output = shell_exec($cmd);
            return $output && strpos($output, (string)$this->pid) !== false;
        }

        $status = proc_get_status($this->processResource);
        return $status['running'];
    }

    public function cancel(): void
    {
        if (\is_resource($this->processResource)) {
            proc_terminate($this->processResource);
            $this->close();
        }
    }

    public function close(): void
    {
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            proc_close($this->processResource);
        }
    }

    /**
     * Clean up status file and directory if logging is disabled and it's in temp directory.
     */
    private function cleanupIfNeeded(): void
    {
        if ($this->loggingEnabled) {
            return;
        }

        if (!$this->isTempStatusFile()) {
            return;
        }

        if (file_exists($this->statusFilePath)) {
            @unlink($this->statusFilePath);
        }

        $statusDir = dirname($this->statusFilePath);
        if (is_dir($statusDir)) {
            $files = @scandir($statusDir);
            if ($files !== false && count($files) === 2) { 
                @rmdir($statusDir);
            }
        }
    }

    /**
     * Check if the status file is in the temp directory.
     */
    private function isTempStatusFile(): bool
    {
        $statusDir = str_replace('\\', '/', realpath(dirname($this->statusFilePath)) ?: dirname($this->statusFilePath));
        $tempDir = str_replace('\\', '/', realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());

        return strpos($statusDir, $tempDir) === 0;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    protected static function getHandler(): BackgroundProcessManager
    {
        if (self::$handler === null) {
            self::$handler = new BackgroundProcessManager;
        }
        return self::$handler;
    }
}