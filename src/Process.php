<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

/**
 * Concrete implementation of a background process that returns a value.
 * Used for parallel() calls.
 *
 * @template TResult
 */
final class Process
{
    private bool $isSystemRunning = true;

    /**
     * @param string $taskId Unique identifier for the task
     * @param int $pid Process ID
     * @param resource $processResource Process resource handle
     * @param PromiseWritableStreamInterface $stdin Standard input stream
     * @param PromiseReadableStreamInterface $stdout Standard output stream
     * @param PromiseReadableStreamInterface $stderr Standard error stream
     * @param string $statusFilePath Path to status file
     * @param bool $loggingEnabled Whether logging is enabled
     */
    public function __construct(
        private readonly string $taskId,
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr,
        private readonly string $statusFilePath,
        private readonly bool $loggingEnabled = true,
        private readonly string $sourceLocation = 'unknown'
    ) {}

    /**
     * Get the result of the background process
     *
     * @param int $timeoutSeconds Maximum time to wait for result in seconds
     * @return PromiseInterface<TResult> Promise that resolves with the task result
     * @throws \RuntimeException If task times out or fails
     */
    public function getResult(int $timeoutSeconds = 60): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            if (PHP_OS_FAMILY === 'Windows') {
                $resultPromise = $this->pollResultFromFile($timeoutSeconds);
            } else {
                $resultPromise = $this->readResultFromStream();
            }

            try {

                return await(Promise::timeout($resultPromise, $timeoutSeconds));
            } catch (TimeoutException) {
                $this->terminate();

                throw new \RuntimeException("Task {$this->taskId} timed out after {$timeoutSeconds} seconds.");
            } catch (\Throwable $e) {
                $this->terminate();

                throw $e;
            } finally {
                $this->close();
                $this->cleanupIfNeeded();
            }
        });
    }

    /**
     * Terminate the process forcefully
     *
     * @return void
     */
    public function terminate(): void
    {
        if (! $this->isSystemRunning) {
            return;
        }

        if ($this->isRunning()) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$this->pid} 2>nul");
            } else {
                exec("pkill -9 -P {$this->pid} 2>/dev/null; kill -9 {$this->pid} 2>/dev/null");
            }
        }
        $this->updateStatusFile('CANCELLED', 'Task cancelled by parent process');
        $this->close();
    }

    /**
     * Check if the process is currently running
     *
     * @return bool True if process is running, false otherwise
     */
    public function isRunning(): bool
    {
        if (! $this->isSystemRunning) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$this->pid}\" 2>nul";
            $output = shell_exec($cmd);
            $this->isSystemRunning = $output !== null && $output !== false && strpos($output, (string)$this->pid) !== false;

            return $this->isSystemRunning;
        }

        if (! \is_resource($this->processResource)) {
            $this->isSystemRunning = false;

            return false;
        }

        $status = proc_get_status($this->processResource);
        $running = $status['running'];

        if (! $running) {
            $this->isSystemRunning = false;
        }

        return $running;
    }

    /**
     * Get the process ID
     *
     * @return int The process ID
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * Get the task ID
     *
     * @return string The task ID
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Read task result from stdout stream (Unix/Linux systems)
     *
     * @return PromiseInterface<TResult> Promise that resolves with task result
     * @throws \RuntimeException If task fails or stream ends unexpectedly
     */
    private function readResultFromStream(): PromiseInterface
    {
        return async(function () {
            while (null !== ($line = await($this->stdout->readLineAsync()))) {
                if (trim($line) === '') {
                    continue;
                }

                $status = @json_decode($line, true);
                if (! \is_array($status)) {
                    continue;
                }

                $statusType = $status['status'] ?? '';

                if ($statusType === 'OUTPUT') {
                    $output = $status['output'] ?? '';
                    if (\is_scalar($output) || (\is_object($output) && method_exists($output, '__toString'))) {
                        echo $output;
                    }
                } elseif ($statusType === 'COMPLETED') {
                    $result = $status['result'] ?? null;

                    if (($status['result_serialized'] ?? false) === true && is_string($result)) {
                        $decoded = base64_decode($result, true);
                        if ($decoded !== false) {
                            $result = unserialize($decoded);
                        }
                    }

                    /** @var TResult */
                    return $result;
                } elseif ($statusType === 'ERROR') {
                    /** @var array<string, mixed> $status */
                    throw $this->createExceptionFromError($status);
                }
            }

            throw new \RuntimeException("Process stream for task {$this->taskId} ended unexpectedly.");
        });
    }

    /**
     * Poll for task result from status file (Windows systems)
     *
     * @param int $timeoutSeconds Maximum time to poll in seconds
     * @return PromiseInterface<TResult> Promise that resolves with task result
     * @throws \RuntimeException If task fails, is cancelled, or times out
     */
    private function pollResultFromFile(int $timeoutSeconds): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $startTime = microtime(true);
            $pollInterval = 0.05;
            $lastOutputPosition = 0;

            while ((microtime(true) - $startTime) < $timeoutSeconds) {
                if (! $this->isSystemRunning && ! file_exists($this->statusFilePath)) {
                    throw new \RuntimeException("Task {$this->taskId} terminated without producing a status file.");
                }

                if (! file_exists($this->statusFilePath)) {
                    await(delay($pollInterval));

                    continue;
                }

                clearstatcache(true, $this->statusFilePath);
                $content = @file_get_contents($this->statusFilePath);
                if ($content === false) {
                    await(delay($pollInterval));

                    continue;
                }

                $status = json_decode($content, true);
                if (! \is_array($status)) {
                    await(delay($pollInterval));

                    continue;
                }

                if (isset($status['buffered_output']) && \is_string($status['buffered_output'])) {
                    $output = $status['buffered_output'];
                    if (\strlen($output) > $lastOutputPosition) {
                        echo substr($output, $lastOutputPosition);
                        $lastOutputPosition = \strlen($output);
                    }
                }

                $currentStatus = $status['status'] ?? '';

                if ($currentStatus === 'COMPLETED') {
                    $result = $status['result'] ?? null;

                    if (($status['result_serialized'] ?? false) === true && \is_string($result)) {
                        $decoded = base64_decode($result, true);
                        if ($decoded !== false) {
                            $result = unserialize($decoded);
                        }
                    }

                    /** @var TResult */
                    return $result;
                } elseif ($currentStatus === 'CANCELLED') {
                    throw new \RuntimeException('Task cancelled.');
                } elseif ($currentStatus === 'ERROR') {
                    /** @var array<string, mixed> $status */
                    throw $this->createExceptionFromError($status);
                }

                await(delay($pollInterval));
            }

            throw new \RuntimeException('Timeout polling status file.');
        });
    }

    /**
     * Reconstructs an exception from the worker error data.
     *
     * @param array<string, mixed> $errorData
     * @return \Throwable
     */
    private function createExceptionFromError(array $errorData): \Throwable
    {
        return ExceptionHandler::createFromWorkerError($errorData, $this->sourceLocation);
    }

    /**
     * Update the status file with new status and message
     *
     * @param string $status Status value (COMPLETED, ERROR, CANCELLED, etc.)
     * @param string $message Status message
     * @return void
     */
    private function updateStatusFile(string $status, string $message): void
    {
        if (file_exists($this->statusFilePath)) {
            $content = @file_get_contents($this->statusFilePath);
            $data = $content !== false ? json_decode($content, true) : [];
            if (! \is_array($data)) {
                $data = [];
            }
            $data['status'] = $status;
            $data['message'] = $message;
            $data['updated_at'] = date('Y-m-d H:i:s');
            @file_put_contents($this->statusFilePath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
    }

    /**
     * Close all streams and process resources
     *
     * @return void
     */
    private function close(): void
    {
        if (! $this->isSystemRunning) {
            return;
        }

        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            proc_close($this->processResource);
        }

        $this->isSystemRunning = false;
    }

    /**
     * Cleanup status file and directory if logging is disabled
     *
     * @return void
     */
    private function cleanupIfNeeded(): void
    {
        if (! $this->loggingEnabled && file_exists($this->statusFilePath)) {
            @unlink($this->statusFilePath);
            $dir = dirname($this->statusFilePath);
            $scanResult = is_dir($dir) ? scandir($dir) : false;
            if ($scanResult !== false && \count($scanResult) <= 2 && strpos($dir, sys_get_temp_dir()) === 0) {
                @rmdir($dir);
            }
        }
    }

    public function __destruct()
    {
        if ($this->isSystemRunning) {
            $this->terminate();
        }
    }
}
