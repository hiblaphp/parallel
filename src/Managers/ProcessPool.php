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
    private SplQueue $idleWorkers;
    private SplQueue $taskQueue;
    private bool $isShutdown = false;

    /** @var array<int, PersistentProcess> */
    private array $allWorkers = [];

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

    public function submit(callable $task, int $timeoutSeconds): Promise
    {
        if ($this->isShutdown) {
            return Promise::rejected(new \RuntimeException('Cannot submit task to a shutdown pool.'));
        }

        $promise = new Promise();

        // If a worker is free, dispatch immediately.
        if (!$this->idleWorkers->isEmpty()) {
            $worker = $this->idleWorkers->dequeue();
            $this->dispatch($worker, $task, $promise, $timeoutSeconds);
        } else {
            // Otherwise, add the task to the queue.
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

        // Reject any tasks that were still in the queue
        while (!$this->taskQueue->isEmpty()) {
            [, $promise] = $this->taskQueue->dequeue();
            /** @var Promise $promise */
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

        $onReady = function (PersistentProcess $worker) {
            if ($this->isShutdown) {
                $worker->terminate();
                unset($this->allWorkers[spl_object_id($worker)]);
                return;
            }

            // If there's a task waiting, give it to this worker
            if (!$this->taskQueue->isEmpty()) {
                [$task, $promise, $timeout] = $this->taskQueue->dequeue();
                $this->dispatch($worker, $task, $promise, $timeout);
            } else {
                // Otherwise, add it to the idle queue
                $this->idleWorkers->enqueue($worker);
            }
        };

        $process->startReadLoop($onReady);
    }

    private function dispatch(PersistentProcess $worker, callable $task, Promise $promise, int $timeoutSeconds): void
    {
        $taskId = $this->systemUtils->generateTaskId();

        $payload = [
            'task_id' => $taskId,
            'serialized_callback' => $this->serializer->serializeCallback($task)
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

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
