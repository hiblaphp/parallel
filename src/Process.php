<?php

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Interfaces\ProcessInterface;
use Hibla\Parallel\Managers\BackgroundProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use SebastianBergmann\Invoker\TimeoutException;

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

/**
 * Concrete implementation of a background process.
 * 
 * @template TResult
 * @implements ProcessInterface<TResult>
 */
final class Process implements ProcessInterface
{
    private static ?BackgroundProcessManager $handler = null;

    /**
     * Constructs a new Process instance.
     * 
     * @internal This should only be constructed by the ProcessSpawnHandler.
     * 
     * @param string $taskId Unique identifier for this task
     * @param int $pid Operating system process ID
     * @param resource $processResource The process resource from proc_open()
     * @param PromiseWritableStreamInterface $stdin Standard input stream
     * @param PromiseReadableStreamInterface $stdout Standard output stream
     * @param PromiseReadableStreamInterface $stderr Standard error stream
     * @param string $statusFilePath Path to the status file for this process
     * @param bool $loggingEnabled Whether detailed logging is enabled
     */
    public function __construct(
        private readonly string $taskId,
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr,
        private readonly string $statusFilePath,
        private readonly bool $loggingEnabled = true
    ) {}

    /**
     * Spawns a new background process to run the given callback.
     * 
     * The callback will be executed in a separate PHP process, allowing for
     * true parallel execution without blocking the main event loop.
     *
     * @template T
     * @param callable(): T $callback The task function to run in the background process
     * @param array<string, mixed> $context Optional context data to pass to the task
     * @return PromiseInterface<ProcessInterface<T>> A promise that resolves with the Process instance
     * @throws \RuntimeException If process spawning fails
     * 
     * @example
     * ```php
     * $process = await(Process::spawn(function () {
     *     sleep(5);
     *     return "Task completed!";
     * }));
     * 
     * $result = await($process->await());
     * echo $result; // "Task completed!"
     * ```
     */
    public static function spawn(callable $callback, array $context = []): PromiseInterface
    {
        return async(function () use ($callback, $context) {
            return await(self::spawnTask($callback, $context));
        });
    }

    /**
     * {@inheritDoc}
     */
    public function await(int $timeoutSeconds = 60): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            if (PHP_OS_FAMILY === 'Windows') {
                $resultPromise = $this->pollResultFromFile($timeoutSeconds);
            } else {
                $resultPromise = $this->readResultFromStream();
            }

            try {
                $result = await(Promise::timeout($resultPromise, $timeoutSeconds));

                return $result;
            } catch (TimeoutException) {
                $this->cancel();
                throw new \RuntimeException("Task {$this->taskId} timed out after {$timeoutSeconds} seconds.");
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
     * {@inheritDoc}
     */
    public function cancel(): void
    {
        if ($this->isRunning()) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$this->pid} 2>nul");
            } else {
                exec("pkill -9 -P {$this->pid} 2>/dev/null; kill -9 {$this->pid} 2>/dev/null");
            }
        }

        if (file_exists($this->statusFilePath)) {
            $content = @file_get_contents($this->statusFilePath);
            $statusData = $content ? json_decode($content, true) : [];

            if (!is_array($statusData)) {
                $statusData = [];
            }

            $statusData = array_merge($statusData, [
                'status' => 'CANCELLED',
                'message' => 'Task cancelled by parent process',
                'pid' => $this->pid,
                'updated_at' => date('Y-m-d H:i:s'),
                'buffered_output' => $statusData['buffered_output'] ?? '',
            ]);

            @file_put_contents(
                $this->statusFilePath,
                json_encode($statusData, JSON_UNESCAPED_SLASHES)
            );
        }

        $this->close();
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * {@inheritDoc}
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Reads task result from stdout stream (Linux strategy).
     * 
     * Efficient, event-driven approach that reads JSON-encoded status updates
     * from the worker process's stdout stream.
     *
     * @return PromiseInterface<TResult> Promise resolving to the task result
     * @throws \RuntimeException If the stream ends unexpectedly or task fails
     */
    private function readResultFromStream(): PromiseInterface
    {
        return async(function () {
            while (null !== ($line = await( $this->stdout->readLineAsync()))) {
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
     * Polls task result from status file (Windows strategy).
     * 
     * Prevents blocking the main event loop on Windows where pipes can cause issues.
     * Polls the status file at regular intervals to check for completion.
     * 
     * @param int $timeoutSeconds Maximum time to wait for the result
     * @return PromiseInterface<TResult> Promise resolving to the task result
     * @throws \RuntimeException If polling times out or task fails
     */
    private function pollResultFromFile(int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $startTime = microtime(true);
            $pollInterval = 0.01;
            $lastOutputPosition = 0;

            while ((microtime(true) - $startTime) < $timeoutSeconds) {
                if (!$this->isRunning()) {
                    throw new \RuntimeException("Task {$this->taskId} was terminated.");
                }

                if (!file_exists($this->statusFilePath)) {
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

                if (isset($status['buffered_output'])) {
                    $output = $status['buffered_output'];
                    if (\strlen($output) > $lastOutputPosition) {
                        echo substr($output, $lastOutputPosition);
                        $lastOutputPosition = \strlen($output);
                    }
                }

                if ($currentStatus === 'CANCELLED') {
                    throw new \RuntimeException("Task {$this->taskId} was cancelled.");
                }

                if ($currentStatus === 'COMPLETED') {
                    return $status['result'] ?? null;
                }

                if ($currentStatus === 'ERROR') {
                    $errorMessage = $status['message'] ?? 'Unknown error';
                    throw new \RuntimeException("Task {$this->taskId} failed: {$errorMessage}");
                }

                await(delay($pollInterval));
            }

            throw new \RuntimeException("Task {$this->taskId} polling timed out after {$timeoutSeconds} seconds.");
        });
    }

    /**
     * Closes all streams and process resources.
     * 
     * @return void
     */
    private function close(): void
    {
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            proc_close($this->processResource);
        }
    }

    /**
     * Cleans up temporary status files if logging is disabled.
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

            if ($files !== false && count($files) <= 2) {
                @rmdir($statusDir);
            }
        }
    }

    /**
     * Internal method to spawn a process asynchronously.
     *
     * @template T
     * @param callable(): T $callback The task function to run
     * @param array<string, mixed> $context Optional context to pass to the task
     * @return PromiseInterface<ProcessInterface<T>> A promise that resolves with the Process instance
     */
    private static function spawnTask(callable $callback, array $context = []): PromiseInterface
    {
        return Promise::resolved(self::getHandler()->spawnStreamedTask($callback, $context));
    }

    /**
     * @return bool True if the status file is in temp directory, false otherwise
     */
    private function isTempStatusFile(): bool
    {
        $statusDir = str_replace('\\', '/', realpath(dirname($this->statusFilePath)) ?: dirname($this->statusFilePath));
        $tempDir = str_replace('\\', '/', realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());

        return strpos($statusDir, $tempDir) === 0;
    }

    /**
     * Gets or creates the singleton BackgroundProcessManager instance.
     *
     * @return BackgroundProcessManager The process manager instance
     */
    private static function getHandler(): BackgroundProcessManager
    {
        if (self::$handler === null) {
            self::$handler = new BackgroundProcessManager;
        }

        return self::$handler;
    }
}
