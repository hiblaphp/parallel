<?php

namespace Hibla\Parallel;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use SebastianBergmann\Invoker\TimeoutException;

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

/**
 * Concrete implementation of a background process that returns a value.
 * Used for parallel() calls.
 * 
 * @template TResult
 */
final class Process
{
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
        private readonly bool $loggingEnabled = true
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
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$this->pid}\" 2>nul";
            $output = shell_exec($cmd);
            return $output !== null && strpos($output, (string)$this->pid) !== false;
        }

        if (!is_resource($this->processResource)) {
            return false;
        }

        $status = proc_get_status($this->processResource);
        return $status['running'] ?? false;
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
                if (trim($line) === '') continue;

                $status = @json_decode($line, true);
                if (!\is_array($status)) continue;

                $statusType = $status['status'] ?? '';

                if ($statusType === 'OUTPUT') {
                    echo $status['output'] ?? '';
                } elseif ($statusType === 'COMPLETED') {
                    return $status['result'] ?? null;
                } elseif ($statusType === 'ERROR') {
                    throw new \RuntimeException("Task {$this->taskId} failed: " . ($status['message'] ?? 'Unknown error'));
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
                if (!file_exists($this->statusFilePath)) {
                    if (!$this->isRunning()) return null;
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
                if (!\is_array($status)) {
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

                if (($status['status'] ?? '') === 'COMPLETED') return $status['result'] ?? null;
                if (($status['status'] ?? '') === 'CANCELLED') throw new \RuntimeException("Task cancelled.");
                if (($status['status'] ?? '') === 'ERROR') throw new \RuntimeException("Task failed: " . ($status['message'] ?? 'Unknown'));

                await(delay($pollInterval));
            }
            throw new \RuntimeException("Timeout polling status file.");
        });
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
            if (!\is_array($data)) {
                $data = [];
            }
            $data['status'] = $status;
            $data['message'] = $message;
            $data['updated_at'] = date('Y-m-d H:i:s');
            @file_put_contents($this->statusFilePath, json_encode($data, JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Close all streams and process resources
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
     * Cleanup status file and directory if logging is disabled
     *
     * @return void
     */
    private function cleanupIfNeeded(): void
    {
        if (!$this->loggingEnabled && file_exists($this->statusFilePath)) {
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
       $this->terminate();
    }
}
