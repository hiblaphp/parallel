<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Handlers\ExceptionHandler;
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
    private bool $isAlive = true;
    private bool $isBusy = true;

    /**
     *  @var array<string, Promise> A map of task IDs to their pending promises. 
     */
    private array $pendingTasks = [];

    /**
     *  @var callable(self): void 
     */
    private $onReadyCallback;

    public function __construct(
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr
    ) {}

    /**
     * Starts the continuous stream reading loop.
     */
    public function startReadLoop(callable $onReadyCallback): void
    {
        $this->onReadyCallback = $onReadyCallback;

        async(function () {
            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    if (trim($line) === '') continue;

                    $data = @json_decode($line, true);
                    if (!\is_array($data)) continue;

                    $status = $data['status'] ?? '';

                    if ($status === 'READY') {
                        $this->isBusy = false;
                        ($this->onReadyCallback)($this);
                        continue;
                    }

                    $taskId = $data['task_id'] ?? null;
                    if (!$taskId || !isset($this->pendingTasks[$taskId])) continue;

                    $promise = $this->pendingTasks[$taskId];

                    if ($status === 'OUTPUT') {
                        echo $data['output'] ?? '';
                    } elseif ($status === 'COMPLETED') {
                        $result = $data['result'] ?? null;
                        if (($data['result_serialized'] ?? false) && is_string($result)) {
                            $result = unserialize(base64_decode($result));
                        }

                        unset($this->pendingTasks[$taskId]);
                        $promise->resolve($result);
                    } elseif ($status === 'ERROR') {
                        $exception = ExceptionHandler::createFromWorkerError($data, 'unknown');
                        unset($this->pendingTasks[$taskId]);
                        $promise->reject($exception);
                    }
                }
            } catch (\Throwable $e) {
                // Stream closed or crashed
            } finally {
                $this->handleCrash();
            }
        });
    }

    /**
     * Submits a new task payload to this worker and returns a promise for its result.
     */
    public function submitTask(string $taskId, string $payload): PromiseInterface
    {
        $this->isBusy = true;

        $promise = new Promise();
        $this->pendingTasks[$taskId] = $promise;

        async(fn() => await($this->stdin->writeAsync($payload . PHP_EOL)));

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
        if (!$this->isAlive) return;

        $this->isAlive = false;
        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            proc_terminate($this->processResource);
            proc_close($this->processResource);
        }

        $this->handleCrash();
    }

    /**
     * Rejects all pending tasks when the worker process dies unexpectedly.
     */
    private function handleCrash(): void
    {
        if (!$this->isAlive) return;

        $this->isAlive = false;
        $this->isBusy = false;

        foreach ($this->pendingTasks as $taskId => $promise) {
            $promise->reject(new \RuntimeException("Persistent worker PID {$this->pid} crashed or stream closed while executing task {$taskId}."));
        }

        $this->pendingTasks = [];
    }
}
