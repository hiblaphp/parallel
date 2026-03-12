<?php

declare(strict_types=1);

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\PersistentProcess;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Promise\Promise;
use Rcalicdan\Serializer\CallbackSerializationManager;
use SplQueue;

/**
 * @internal Manages a pool of persistent worker processes.
 */
final class ProcessPool
{
    /**
     * @var SplQueue<PersistentProcess>
     */
    private SplQueue $idleWorkers;

    /**
     * @var SplQueue<array{0: callable(): mixed, 1: Promise<mixed>, 2: int}>
     */
    private SplQueue $taskQueue;

    /**
     * @var array<int, PersistentProcess>
     */
    private array $allWorkers = [];

    private bool $isShutdown = false;

    /**
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null} $frameworkInfo
     */
    public function __construct(
        private readonly int $size,
        private readonly ProcessSpawnHandler $spawnHandler,
        private readonly CallbackSerializationManager $serializer,
        private readonly SystemUtilities $systemUtils,
        private readonly array $frameworkInfo,
        private readonly ?string $memoryLimit,
        private readonly int $maxNestingLevel
    ) {
        $this->idleWorkers = new SplQueue();
        $this->taskQueue = new SplQueue();
        $this->initialize();
    }

    private function initialize(): void
    {
        for ($i = 0; $i < $this->size; ++$i) {
            $this->spawnWorker();
        }
    }

    /**
     * @template TValue
     * @param callable(): TValue $task
     * @return Promise<TValue>
     */
    public function submit(callable $task, int $timeoutSeconds): Promise
    {
        if ($this->isShutdown) {
            /** @var Promise<TValue> $promise */
            $promise = new Promise();
            $promise->reject(new \RuntimeException('Cannot submit task to a shutdown pool.'));

            return $promise;
        }

        /** @var Promise<TValue> $promise */
        $promise = new Promise();

        if (! $this->idleWorkers->isEmpty()) {
            $worker = $this->idleWorkers->dequeue();
            $this->dispatch($worker, $task, $promise, $timeoutSeconds);
        } else {
            $this->taskQueue->enqueue([$task, $promise, $timeoutSeconds]);
        }

        return $promise;
    }

    public function shutdown(): void
    {
        if ($this->isShutdown) {
            return;
        }
        $this->isShutdown = true;

        foreach ($this->allWorkers as $worker) {
            $worker->terminate();
        }

        while (! $this->taskQueue->isEmpty()) {
            [, $promise] = $this->taskQueue->dequeue();
            /** @var Promise<mixed> $promise */
            $promise->reject(new \RuntimeException('Pool was shut down before the task could be processed.'));
        }

        $this->allWorkers = [];
    }

    private function spawnWorker(): void
    {
        $process = $this->spawnHandler->spawnPersistentWorker(
            $this->frameworkInfo,
            $this->serializer,
            $this->memoryLimit,
            $this->maxNestingLevel
        );

        $this->allWorkers[spl_object_id($process)] = $process;

        $onReady = function (PersistentProcess $worker): void {
            if ($this->isShutdown) {
                $worker->terminate();
                unset($this->allWorkers[spl_object_id($worker)]);

                return;
            }

            if (! $this->taskQueue->isEmpty()) {
                /** @var array{0: callable(): mixed, 1: Promise<mixed>, 2: int} $queued */
                $queued = $this->taskQueue->dequeue();
                [$task, $promise, $timeout] = $queued;
                $this->dispatch($worker, $task, $promise, $timeout);
            } else {
                $this->idleWorkers->enqueue($worker);
            }
        };

        $onCrash = function (PersistentProcess $worker): void {
            unset($this->allWorkers[spl_object_id($worker)]);

            if ($this->isShutdown) {
                return;
            }

            if (\count($this->allWorkers) < $this->size) {
                $this->spawnWorker();
            }
        };

        $process->startReadLoop($onReady, $onCrash);
    }

    /**
     * @template TValue
     * @param callable(): TValue $task
     * @param Promise<TValue> $promise
     */
    private function dispatch(PersistentProcess $worker, callable $task, Promise $promise, int $timeoutSeconds): void
    {
        $taskId = $this->systemUtils->generateTaskId();

        $payload = [
            'task_id' => $taskId,
            'serialized_callback' => $this->serializer->serializeCallback($task),
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($jsonPayload === false) {
            $promise->reject(new \RuntimeException('Failed to encode task payload: ' . json_last_error_msg()));

            return;
        }

        $executionPromise = $worker->submitTask($taskId, $jsonPayload);

        if ($timeoutSeconds > 0) {
            $executionPromise = Promise::timeout($executionPromise, $timeoutSeconds);
        }

        $executionPromise->then(
            $promise->resolve(...),
            $promise->reject(...)
        );
    }
}
