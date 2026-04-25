<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Parallel\Utilities\ProcessKiller;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

use function Hibla\async;
use function Hibla\await;

/**
 * @internal Represents a single, long-running persistent worker process.
 */
final class PersistentProcess
{
    /**
     * @var array<string, array{promise: Promise<mixed>, location: string, onMessage: callable|null}>
     */
    private array $pendingTasks = [];

    /**
     * @var callable(self): void
     */
    private $onReadyCallback;

    /**
     * @var callable(self): void
     */
    private $onCrashCallback;

    private bool $isAlive = true;

    private bool $isBusy = true;

    /**
     * @var int|null
     */
    private ?int $workerPid = null;

    public function __construct(
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr
    ) {
    }

    /**
     * @param callable(self): void $onReadyCallback
     * @param callable(self): void $onCrashCallback
     */
    public function startReadLoop(callable $onReadyCallback, callable $onCrashCallback): void
    {
        $this->onReadyCallback = $onReadyCallback;
        $this->onCrashCallback = $onCrashCallback;

        async(function () {
            /** @var array<string, list<PromiseInterface<mixed>>> $pendingHandlers */
            $pendingHandlers = [];
            $buffer = ''; // JSON Reassembly Buffer

            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    $buffer .= $line;

                    if (trim($buffer) === '') {
                        $buffer = '';

                        continue;
                    }

                    $data = @json_decode($buffer, true);

                    // If decoding fails, the payload was likely truncated by a 64KB stream limit.
                    // Keep the buffer and wait for the next chunk to append.
                    if (! \is_array($data)) {
                        continue;
                    }

                    // Successful decode: clear the buffer for the next frame
                    $buffer = '';

                    /** @var array<string, mixed> $data */
                    $status = isset($data['status']) && is_string($data['status'])
                        ? $data['status']
                        : '';

                    if ($status === 'CRASHED' || $status === 'RETIRING') {
                        $this->terminate();
                        ($this->onCrashCallback)($this);

                        break;
                    }

                    if ($status === 'READY') {
                        if (isset($data['pid']) && \is_int($data['pid'])) {
                            $this->workerPid = $data['pid'];
                        }

                        $this->isBusy = false;
                        ($this->onReadyCallback)($this);

                        continue;
                    }

                    $taskId = isset($data['task_id']) && is_string($data['task_id'])
                        ? $data['task_id']
                        : null;

                    if ($taskId === null || ! isset($this->pendingTasks[$taskId])) {
                        continue;
                    }

                    $taskMeta = $this->pendingTasks[$taskId];
                    $promise = $taskMeta['promise'];
                    $sourceLocation = $taskMeta['location'];

                    if ($status === 'OUTPUT') {
                        $output = $data['output'] ?? '';
                        echo \is_string($output) ? $output : '';
                    } elseif ($status === 'MESSAGE') {
                        $onMessage = $this->pendingTasks[$taskId]['onMessage'];

                        if ($onMessage !== null) {
                            $rawData = $data['data'] ?? null;

                            // Transparently deserialize objects serialized by emit()
                            // using the base64(serialize()) pattern
                            if (($data['data_serialized'] ?? false) === true && \is_string($rawData)) {
                                $decoded = base64_decode($rawData, true);
                                if ($decoded !== false) {
                                    $rawData = unserialize($decoded);
                                }
                            }

                            $message = new WorkerMessage(
                                data: $rawData,
                                pid: \is_int($data['pid']) ? $data['pid'] : $this->pid,
                            );

                            // Track handler fiber under its task ID so the terminal
                            // frame can await all handlers for this specific task.
                            $pendingHandlers[$taskId][] = async(fn () => $onMessage($message));
                        }
                    } elseif ($status === 'COMPLETED') {
                        $result = $data['result'] ?? null;

                        if (($data['result_serialized'] ?? false) === true && \is_string($result)) {
                            $decoded = base64_decode($result, true);
                            if ($decoded !== false) {
                                $result = unserialize($decoded);
                            }
                        }

                        if (isset($pendingHandlers[$taskId]) && \count($pendingHandlers[$taskId]) > 0) {
                            await(Promise::all($pendingHandlers[$taskId]));
                            unset($pendingHandlers[$taskId]);
                        }

                        unset($this->pendingTasks[$taskId]);
                        $promise->resolve($result);
                    } elseif ($status === 'ERROR') {
                        /** @var array<string, mixed> $data */
                        $exception = ExceptionHandler::createFromWorkerError($data, $sourceLocation);

                        if (isset($pendingHandlers[$taskId]) && \count($pendingHandlers[$taskId]) > 0) {
                            await(Promise::all($pendingHandlers[$taskId]));
                            unset($pendingHandlers[$taskId]);
                        }

                        unset($this->pendingTasks[$taskId]);
                        $promise->reject($exception);
                    }
                }
            } catch (\Throwable $e) {
                // Stream closed unexpectedly or any other error — treat as a crash
                // and clear all pending handler references before crashing.
                $pendingHandlers = [];
                $this->terminate();
                ($this->onCrashCallback)($this);
            } finally {
                $pendingHandlers = [];
                $this->terminate();
            }
        });
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getWorkerPid(): int
    {
        return $this->workerPid ?? $this->pid;
    }

    /**
     * @param callable(WorkerMessage): void|null $onMessage
     *
     * @return PromiseInterface<mixed>
     */
    public function submitTask(string $taskId, string $payload, string $sourceLocation = 'unknown', ?callable $onMessage = null): PromiseInterface
    {
        $this->isBusy = true;

        /** @var Promise<mixed> $promise */
        $promise = new Promise();

        $this->pendingTasks[$taskId] = [
            'promise' => $promise,
            'location' => $sourceLocation,
            'onMessage' => $onMessage,
        ];

        async(function () use ($payload) {
            try {
                if (! $this->isAlive) {
                    return;
                }

                await($this->stdin->writeAsync($payload . PHP_EOL));
            } catch (\Hibla\Stream\Exceptions\StreamException) {
            }
        });

        return $promise;
    }

    public function isBusy(): bool
    {
        return $this->isBusy;
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    /**
     * Sends a termination signal to the worker process without blocking.
     *
     * @param bool $executeOsKill If true, issues the OS command to kill the tree. If false,
     *                            only internal state is updated (used during batched pool shutdown).
     */
    public function signalTerminate(bool $executeOsKill = true): void
    {
        if (! $this->isAlive) {
            return;
        }

        $this->handleCrash();

        if ($executeOsKill) {
            ProcessKiller::killTreesAsync([$this->pid]);
        }
    }

    /**
     * Closes all I/O streams and optionally releases the proc resource.
     *
     * @param bool $closeProcessResource If false, the proc_open resource is left alive
     *                                   so the Windows tree structure isn't broken.
     */
    public function cleanupResources(bool $closeProcessResource = true): void
    {
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if ($closeProcessResource && \is_resource($this->processResource)) {
            if (PHP_OS_FAMILY === 'Windows') {
                @proc_terminate($this->processResource);
            } else {
                @proc_terminate($this->processResource);
                @proc_close($this->processResource);
            }
        }
    }

    /**
     * Terminates the worker process and releases all associated resources.
     */
    public function terminate(): void
    {
        if (! $this->isAlive) {
            return;
        }

        $this->signalTerminate(true);
        $this->cleanupResources(PHP_OS_FAMILY !== 'Windows');
    }

    private function handleCrash(): void
    {
        $this->isAlive = false;
        $this->isBusy = false;

        foreach ($this->pendingTasks as $taskId => $taskMeta) {
            $taskMeta['promise']->reject(new ProcessCrashedException(
                "Persistent worker PID {$this->pid} crashed or stream closed while executing task {$taskId}."
            ));
        }

        $this->pendingTasks = [];
    }
}
