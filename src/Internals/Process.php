<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

use function Hibla\async;
use function Hibla\await;

use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Exceptions\TimeoutException;
use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Parallel\Utilities\ProcessKiller;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Exceptions\TimeoutException as PromiseTimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

/**
 * @internal
 *
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
     * @param callable(WorkerMessage): void|null $onMessage Optional handler invoked for each
     *        MESSAGE frame emitted by the worker via emit(). The handler promise is tracked
     *        and awaited before the task promise resolves — callers are never released before
     *        handlers finish executing.
     * @return PromiseInterface<TResult> Promise that resolves with the task result
     * @throws \RuntimeException If the task times out, fails, or the stream closes unexpectedly
     */
    public function getResult(int $timeoutSeconds = 60, ?callable $onMessage = null): PromiseInterface
    {
        return async(function () use ($timeoutSeconds, $onMessage) {
            $resultPromise = $this->readResultFromStream($onMessage);

            try {
                if ($timeoutSeconds > 0) {
                    return await(Promise::timeout($resultPromise, $timeoutSeconds));
                }

                return await($resultPromise);
            } catch (PromiseTimeoutException) {
                $this->terminate();

                throw new TimeoutException("Process timeout after {$timeoutSeconds} seconds");
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
     * On Windows, proc_open() is called with bypass_shell => true in
     * ProcessSpawnHandler, which means the process resource maps directly
     * to the PHP worker with no cmd.exe wrapper. proc_terminate() therefore
     * calls TerminateProcess() on the correct PID immediately — no external
     * process (taskkill/cmd.exe) needs to be spawned.
     *
     * On Unix, pkill/kill cover the child tree since proc_terminate() only
     * signals the direct process.
     *
     * Safe to call multiple times — subsequent calls are no-ops once
     * isSystemRunning is false.
     *
     * @return void
     */
    public function terminate(): void
    {
        if (! $this->isSystemRunning) {
            return;
        }

        ProcessKiller::killTreesAsync([$this->pid]);

        $this->close(PHP_OS_FAMILY !== 'Windows');
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
     *   - MESSAGE:   Constructs a WorkerMessage, schedules the handler as an async()
     *                fiber, and tracks the promise. All pending handler promises are
     *                awaited via Promise::all() before the terminal frame resolves —
     *                ensuring callers are never released before handlers finish.
     *   - COMPLETED: Awaits pending handlers, deserializes result, closes stdin.
     *   - ERROR:     Awaits pending handlers, reconstructs and rethrows the exception.
     *   - TIMEOUT:   Awaits pending handlers, throws a TimeoutException.
     *
     * A StreamException is caught and treated as socket EOF — on Windows the
     * socket closure from the worker side surfaces as a StreamException rather
     * than readLineAsync() returning null as it would on a clean pipe EOF.
     *
     * @param callable(WorkerMessage): void|null $onMessage Optional message handler
     * @return PromiseInterface<TResult> Promise that resolves with the task result
     */
    private function readResultFromStream(?callable $onMessage): PromiseInterface
    {
        return async(function () use ($onMessage) {
            // Tracks all in-flight handler fibers so we can await them before
            // resolving the task promise — prevents .wait() from returning before
            // handlers have finished executing.
            /** @var list<PromiseInterface<mixed>> $pendingHandlers */
            $pendingHandlers = [];

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
                    } elseif ($statusType === 'MESSAGE') {
                        if ($onMessage !== null) {
                            $data = $status['data'] ?? null;

                            // Transparently deserialize objects serialized by emit()
                            // using the base64(serialize()) pattern
                            if (($status['data_serialized'] ?? false) === true && \is_string($data)) {
                                $decoded = base64_decode($data, true);
                                if ($decoded !== false) {
                                    $data = unserialize($decoded);
                                }
                            }

                            $message = new WorkerMessage(
                                data: $data,
                                pid: \is_int($status['pid']) ? $status['pid'] : $this->pid,
                            );
                            $pendingHandlers[] = async(fn () => $onMessage($message));
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

                        if (\count($pendingHandlers) > 0) {
                            await(Promise::all($pendingHandlers));
                            $pendingHandlers = [];
                        }

                        /** @var TResult */
                        return $result;
                    } elseif ($statusType === 'ERROR') {
                        // Close stdin to unblock the worker's drain_and_wait() loop
                        // before rethrowing so the worker doesn't hang on exit.
                        $this->stdin->close();

                        if (\count($pendingHandlers) > 0) {
                            await(Promise::all($pendingHandlers));
                            $pendingHandlers = [];
                        }

                        /** @var array<string, mixed> $status */
                        throw $this->createExceptionFromError($status);
                    } elseif ($statusType === 'TIMEOUT') {
                        $this->stdin->close();

                        if (\count($pendingHandlers) > 0) {
                            await(Promise::all($pendingHandlers));
                            $pendingHandlers = [];
                        }

                        throw new TimeoutException("Process PID {$this->pid} timed out.");
                    }
                }
            } catch (StreamException) {
                // The worker closed its socket end before readLineAsync() returned
                // null — this is normal on Windows when the process exits. If a
                // terminal frame was already handled above we never reach this point.
            } finally {
                $pendingHandlers = [];
            }

            throw new ProcessCrashedException("Process stream for PID {$this->pid} ended unexpectedly.");
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
    private function close(bool $closeProcessResource = true): void
    {
        if (! $this->isSystemRunning) {
            return;
        }

        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if ($closeProcessResource && \is_resource($this->processResource)) {
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
