<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Managers\BackgroundProcessManager;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use function Hibla\async;
use function Hibla\await;

/**
 * Represents a live, running background process with stream-based communication.
 * The static methods provide the primary, user-friendly API.
 */
class Process
{
    protected static ?BackgroundProcessManager $handler = null;
    private int $pid;
    private $processResource;
    private PromiseWritableStreamInterface $stdin;
    private PromiseReadableStreamInterface $stdout;
    private PromiseReadableStreamInterface $stderr;
    private string $taskId;

    /**
     * @internal This should only be constructed by the ProcessSpawnHandler.
     */
    public function __construct(
        string $taskId,
        int $pid,
        $processResource,
        PromiseWritableStreamInterface $stdin,
        PromiseReadableStreamInterface $stdout,
        PromiseReadableStreamInterface $stderr
    ) {
        $this->taskId = $taskId;
        $this->pid = $pid;
        $this->processResource = $processResource;
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    /**
     * Spawns a background task and returns a Promise resolving to a Process object.
     * This is the main entry point for creating parallel processes.
     */
    public static function spawn(callable $callback, array $context = []): Process
    {
        return self::getHandler()->spawnStreamedTask($callback, $context);
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
            $resultPromise = $this->readResultFromStream();

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
            }
        });
    }

    /**
     * Internal helper to read the final result from the process's stdout stream.
     */
    /**
     * Internal helper to read the final result from the process's stdout stream.
     */
    /**
     * Internal helper to read the final result from the process's stdout stream.
     */
    private function readResultFromStream(): PromiseInterface
    {
        return async(function () {
            while (null !== ($line = await($this->stdout->readLineAsync()))) {
                $status = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                if ($status['status'] === 'OUTPUT') {
                    echo $status['output'];
                    flush();
                    continue;
                }

                if ($status['status'] === 'COMPLETED') {
                    return $status['result'] ?? null;
                }

                if ($status['status'] === 'ERROR') {
                    $errorMessage = $status['message'] ?? 'Unknown error';
                    throw new \RuntimeException("Task {$this->taskId} failed in child process: {$errorMessage}");
                }
            }

            throw new \RuntimeException("Process stream for task {$this->taskId} ended unexpectedly.");
        });
    }

    /**
     * Immediately terminates the background process.
     */
    public function cancel(): void
    {
        if (\is_resource($this->processResource)) {
            proc_terminate($this->processResource);
            $this->close();
        }
    }

    /**
     * Closes all communication pipes and the process resource.
     */
    public function close(): void
    {
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            proc_close($this->processResource);
        }
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
