<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

use function Hibla\async;
use function Hibla\await;

use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

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
            // Tracks in-flight handler fibers per task ID so COMPLETED/ERROR
            // can await all handlers for that specific task before resolving
            // its promise — prevents callers being released before handlers finish.
            /** @var array<string, list<PromiseInterface<mixed>>> $pendingHandlers */
            $pendingHandlers = [];

            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $data = @json_decode($line, true);
                    if (! \is_array($data)) {
                        continue;
                    }

                    /** @var array<string, mixed> $data */
                    $status = isset($data['status']) && is_string($data['status'])
                        ? $data['status']
                        : '';

                    if ($status === 'CRASHED') {
                        $this->terminate();
                        ($this->onCrashCallback)($this);

                        break;
                    }

                    if ($status === 'READY') {
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

    /**
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *        The handler promise is tracked by startReadLoop() and awaited before the task
     *        promise resolves — callers are never released before handlers finish.
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
                // If the process was terminated before the event loop
                // ran this closure, we skip the write entirely.
                if (! $this->isAlive) {
                    return;
                }

                await($this->stdin->writeAsync($payload . PHP_EOL));
            } catch (\Hibla\Stream\Exceptions\StreamException) {
                // Ignore: This happens if the process is killed (via cancellation)
                // while this asynchronous write is still in the event loop queue.
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

    public function terminate(): void
    {
        if (! $this->isAlive) {
            return;
        }

        $this->handleCrash();

        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID {$this->pid} 2>nul");
        } else {
            exec("pkill -9 -P {$this->pid} 2>/dev/null; kill -9 {$this->pid} 2>/dev/null");
        }

        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            @proc_terminate($this->processResource);
            @proc_close($this->processResource);
        }
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
