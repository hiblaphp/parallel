<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

use function Hibla\async;
use function Hibla\await;

use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Exceptions\StreamException;
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
     * @param int $pid Process ID
     * @param resource $processResource Process resource handle
     * @param PromiseWritableStreamInterface $stdin Standard input stream
     * @param PromiseReadableStreamInterface $stdout Standard output stream
     * @param PromiseReadableStreamInterface $stderr Standard error stream
     * @param string $sourceLocation Source file and line that spawned this process
     */
    public function __construct(
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr,
        private readonly string $sourceLocation = 'unknown'
    ) {
    }

    /**
     * Get the result of the background process.
     *
     * Reads structured JSON frames from the worker's stdout stream until a
     * terminal frame (COMPLETED, ERROR, or TIMEOUT) is received. Uses
     * socket-pair descriptors which properly support non-blocking I/O on all
     * platforms including Windows, unlike anonymous pipes which ignore
     * stream_set_blocking(false) at the kernel level.
     *
     * @param int $timeoutSeconds Maximum time to wait for result in seconds. 0 means no limit.
     * @return PromiseInterface<TResult> Promise that resolves with the task result
     * @throws \RuntimeException If the task times out, fails, or the stream closes unexpectedly
     */
    public function getResult(int $timeoutSeconds = 60): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $resultPromise = $this->readResultFromStream();

            try {
                if ($timeoutSeconds > 0) {
                    return await(Promise::timeout($resultPromise, $timeoutSeconds));
                }

                return await($resultPromise);
            } catch (TimeoutException) {
                $this->terminate();

                throw new TimeoutException("Process PID {$this->pid} timed out after {$timeoutSeconds} seconds.");
            } catch (\Throwable $e) {
                $this->terminate();

                throw $e;
            } finally {
                $this->close();
            }
        });
    }

    /**
     * Terminate the process forcefully.
     *
     * Sends a force-kill signal to the worker process and any child processes
     * it may have spawned. Safe to call multiple times — subsequent calls are
     * no-ops once the process has already stopped.
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

        $this->close();
    }

    /**
     * Check if the process is currently running.
     *
     * On Unix, uses proc_get_status() for an efficient in-process check.
     * On Windows, falls back to tasklist since proc_get_status() is unreliable
     * for processes opened with socket descriptors on that platform.
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
     * Get the process ID.
     *
     * @return int The OS-level process ID of the worker
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * Read the task result from the stdout socket stream.
     *
     * Processes structured JSON frames emitted by the worker:
     *   - OUTPUT:    Forwards buffered echo/print output to the parent's stdout.
     *   - COMPLETED: Deserializes the result and closes stdin to unblock the worker's drain.
     *   - ERROR:     Reconstructs and rethrows the worker exception.
     *   - TIMEOUT:   Throws a TimeoutException with the timeout message.
     *
     * A StreamException is caught and treated as socket EOF — on Windows the
     * socket closure from the worker side surfaces as a StreamException rather
     * than readLineAsync() returning null as it would on a clean pipe EOF.
     * If a terminal frame was already processed before the exception this path
     * is never reached.
     *
     * @return PromiseInterface<TResult> Promise that resolves with the task result
     * @throws \RuntimeException If the task fails or the stream ends without a terminal frame
     */
    private function readResultFromStream(): PromiseInterface
    {
        return async(function () {
            try {
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

                        // Close stdin to unblock the worker's drain_and_wait() loop
                        $this->stdin->close();

                        /** @var TResult */
                        return $result;
                    } elseif ($statusType === 'ERROR') {
                        // Close stdin to unblock the worker's drain_and_wait() loop
                        // before rethrowing so the worker doesn't hang on exit.
                        $this->stdin->close();

                        /** @var array<string, mixed> $status */
                        throw $this->createExceptionFromError($status);
                    } elseif ($statusType === 'TIMEOUT') {
                        $this->stdin->close();

                        throw new TimeoutException("Process PID {$this->pid} timed out.");
                    }
                }
            } catch (StreamException) {
                // The worker closed its socket end before readLineAsync() returned
                // null — this is normal on Windows when the process exits. If a
                // terminal frame was already handled above we never reach this point.
            }

            throw new \RuntimeException("Process stream for PID {$this->pid} ended unexpectedly.");
        });
    }

    /**
     * Reconstructs a Throwable from the structured error data sent by the worker.
     *
     * @param array<string, mixed> $errorData Decoded ERROR frame from the worker
     * @return \Throwable The reconstructed exception
     */
    private function createExceptionFromError(array $errorData): \Throwable
    {
        return ExceptionHandler::createFromWorkerError($errorData, $this->sourceLocation);
    }

    /**
     * Close all streams and the process resource handle.
     *
     * Safe to call multiple times — guarded by the isSystemRunning flag.
     * Sets isSystemRunning to false so subsequent calls from terminate(),
     * getResult()'s finally block, or __destruct() are no-ops.
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
     * Forcefully terminate the process if it is still running when the object
     * is garbage collected. This is a safety net for cases where getResult()
     * was never awaited or the promise was abandoned.
     */
    public function __destruct()
    {
        if ($this->isSystemRunning) {
            $this->terminate();
        }
    }
}
