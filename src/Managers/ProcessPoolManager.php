<?php

declare(strict_types=1);

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Internals\PersistentProcess;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\Serializer\CallbackSerializationManager;
use SplQueue;

/**
 * @internal Manages a pool of persistent worker processes.
 */
final class ProcessPoolManager
{
    /**
     * @var SplQueue<PersistentProcess>
     */
    private SplQueue $idleWorkers;

    /**
     * @var SplQueue<array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string}>
     */
    private SplQueue $taskQueue;

    /**
     * @var array<int, PersistentProcess>
     */
    private array $allWorkers = [];

    /**
     * @var array<int, array<string, Promise<mixed>>> Worker ID -> Task ID -> Promise
     */
    private array $activeTasks = [];

    private bool $isShutdown = false;

    private bool $isShuttingDownGracefully = false;

    /**
     * @var Promise<void>|null
     */
    private ?Promise $shutdownPromise = null;

    /**
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null} $frameworkInfo
     */
    public function __construct(
        private readonly int $size,
        private readonly ProcessSpawnHandler $spawnHandler,
        private readonly CallbackSerializationManager $serializer,
        private readonly array $frameworkInfo,
        private readonly ?string $memoryLimit,
        private readonly int $maxNestingLevel
    ) {
        // Pre-flight check: prevent pool creation if exceeds the max nesting level
        $currentLevel = (int)((($env = getenv('DEFER_NESTING_LEVEL')) !== false) ? $env : 0);

        if ($currentLevel >= $this->maxNestingLevel) {
            throw new \RuntimeException(
                'Cannot create persistent pool: Already at maximum nesting level ' .
                    "{$currentLevel}/{$this->maxNestingLevel}. " .
                    "To increase this limit, configure 'max_nesting_level' in your hibla_parallel config file. " .
                    'Maximum safe limit is 10 levels.'
            );
        }

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
     * @return PromiseInterface<TValue>
     */
    public function submit(callable $task, int $timeoutSeconds, string $sourceLocation = 'unknown'): PromiseInterface
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
            $this->dispatch($worker, $task, $promise, $timeoutSeconds, $sourceLocation);
        } else {
            $this->taskQueue->enqueue([$task, $promise, $timeoutSeconds, $sourceLocation]);
        }

        return $promise;
    }

    /**
     * Gracefully shuts down the pool.
     *
     * Stops accepting new tasks and waits for the queue and active tasks to empty.
     *
     * @return PromiseInterface<void>
     */
    public function shutdownAsync(): PromiseInterface
    {
        if ($this->shutdownPromise !== null) {
            return $this->shutdownPromise;
        }

        /** @var Promise<void> $promise */
        $promise = new Promise();
        $this->shutdownPromise = $promise;
        $this->isShutdown = true;
        $this->isShuttingDownGracefully = true;

        $this->checkGracefulShutdownCompletion();

        return $promise;
    }

    /**
     * Forcefully shuts down the pool.
     */
    public function shutdown(): void
    {
        if ($this->isShutdown && ! $this->isShuttingDownGracefully) {
            return;
        }

        $this->isShutdown = true;
        $this->isShuttingDownGracefully = false; // Override graceful state

        foreach ($this->allWorkers as $worker) {
            $worker->terminate();
        }

        while (! $this->taskQueue->isEmpty()) {
            $taskData = $this->taskQueue->dequeue();
            /** @var Promise<mixed> $promise */
            $promise = $taskData[1];
            $promise->reject(new \RuntimeException('Pool was shut down before the task could be processed.'));
        }

        foreach ($this->activeTasks as $workerId => $tasks) {
            foreach ($tasks as $promise) {
                $promise->reject(new \RuntimeException('Pool was shut down before the task completed.'));
            }
        }

        $this->allWorkers = [];
        $this->activeTasks = [];
        $this->idleWorkers = new SplQueue();

        if ($this->shutdownPromise !== null && ! $this->shutdownPromise->isFulfilled() && ! $this->shutdownPromise->isRejected()) {
            $this->shutdownPromise->resolve(null);
        }
    }

    private function checkGracefulShutdownCompletion(): void
    {
        if (! $this->isShuttingDownGracefully) {
            return;
        }

        // Wait for all queued tasks to be dispatched
        if (! $this->taskQueue->isEmpty()) {
            return;
        }

        // Wait for all active tasks to complete
        foreach ($this->activeTasks as $tasks) {
            if (\count($tasks) > 0) {
                return;
            }
        }

        // All done! Terminate workers and finalize shutdown.
        foreach ($this->allWorkers as $worker) {
            $worker->terminate();
        }

        $this->allWorkers = [];
        $this->activeTasks = [];
        $this->idleWorkers = new SplQueue();

        if ($this->shutdownPromise !== null && ! $this->shutdownPromise->isFulfilled() && ! $this->shutdownPromise->isRejected()) {
            $this->shutdownPromise->resolve(null);
        }
    }

    private function shutdownDueToFatalError(\Throwable $e): void
    {
        $this->isShutdown = true;
        $this->isShuttingDownGracefully = false;

        foreach ($this->allWorkers as $worker) {
            $worker->terminate();
        }
        $this->allWorkers = [];

        while (! $this->taskQueue->isEmpty()) {
            $taskData = $this->taskQueue->dequeue();
            /** @var Promise<mixed> $promise */
            $promise = $taskData[1];
            $promise->reject($e);
        }

        foreach ($this->activeTasks as $workerId => $tasks) {
            foreach ($tasks as $promise) {
                $promise->reject($e);
            }
        }
        $this->activeTasks = [];
        $this->idleWorkers = new SplQueue();

        if ($this->shutdownPromise !== null && ! $this->shutdownPromise->isFulfilled() && ! $this->shutdownPromise->isRejected()) {
            $this->shutdownPromise->reject($e);
        }
    }

    private function spawnWorker(): void
    {
        $process = $this->spawnHandler->spawnPersistentWorker(
            $this->frameworkInfo,
            $this->serializer,
            $this->memoryLimit,
            $this->maxNestingLevel
        );

        $workerId = spl_object_id($process);
        $this->allWorkers[$workerId] = $process;
        $this->activeTasks[$workerId] = [];
        $workerIsReady = false;

        $onReady = function (PersistentProcess $worker) use (&$workerIsReady): void {
            $workerIsReady = true;

            if ($this->isShutdown && ! $this->isShuttingDownGracefully) {
                $worker->terminate();
                unset($this->allWorkers[spl_object_id($worker)]);

                return;
            }

            if (! $this->taskQueue->isEmpty()) {
                /** @var array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string} $queued */
                $queued = $this->taskQueue->dequeue();
                [$task, $promise, $timeout, $sourceLocation] = $queued;
                $this->dispatch($worker, $task, $promise, $timeout, $sourceLocation);
            } else {
                if ($this->isShuttingDownGracefully) {
                    // Graceful shutdown: No tasks left in queue. kill this worker early.
                    $worker->terminate();
                    unset($this->allWorkers[spl_object_id($worker)]);
                    $this->checkGracefulShutdownCompletion();
                } else {
                    $this->idleWorkers->enqueue($worker);
                }
            }
        };

        $onCrash = function (PersistentProcess $worker) use (&$workerIsReady): void {
            $wId = spl_object_id($worker);
            unset($this->allWorkers[$wId]);

            // Check if the worker had any active tasks before trying to access them.
            if (isset($this->activeTasks[$wId]) && \count($this->activeTasks[$wId]) > 0) {
                foreach ($this->activeTasks[$wId] as $pendingPromise) {
                    $pendingPromise->reject(new \RuntimeException('Worker crashed or was forcefully closed while executing task.'));
                }
            }

            unset($this->activeTasks[$wId]);

            if ($this->isShutdown) {
                if ($this->isShuttingDownGracefully) {
                    $this->checkGracefulShutdownCompletion();
                }

                return;
            }

            if (! $workerIsReady) {
                $this->shutdownDueToFatalError(
                    new \RuntimeException('A persistent worker crashed during boot. Check logs for fatal errors (e.g., nesting limits or syntax errors).')
                );

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
    private function dispatch(PersistentProcess $worker, callable $task, Promise $promise, int $timeoutSeconds, string $sourceLocation): void
    {
        $taskId = bin2hex(random_bytes(16));

        try {
            $serializedTask = $this->serializer->serializeCallback($task);
        } catch (\Throwable $e) {
            // Rejects cleanly if the user pass $pool via "use ($pool)" to a nested closure
            $promise->reject(new \RuntimeException('Failed to serialize task payload: ' . $e->getMessage(), 0, $e));
            $this->idleWorkers->enqueue($worker);

            return;
        }

        $payload = [
            'task_id' => $taskId,
            'serialized_callback' => $serializedTask,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($jsonPayload === false) {
            $promise->reject(new \RuntimeException('Failed to encode task payload: ' . json_last_error_msg()));
            $this->idleWorkers->enqueue($worker);

            return;
        }

        $workerId = spl_object_id($worker);
        $this->activeTasks[$workerId][$taskId] = $promise;

        $executionPromise = $worker->submitTask($taskId, $jsonPayload, $sourceLocation);

        if ($timeoutSeconds > 0) {
            $executionPromise = Promise::timeout($executionPromise, $timeoutSeconds);
        }

        $executionPromise->then(
            function ($value) use ($workerId, $taskId, $promise) {
                unset($this->activeTasks[$workerId][$taskId]);
                $promise->resolve($value);
                $this->checkGracefulShutdownCompletion();
            },
            function ($reason) use ($workerId, $taskId, $promise) {
                unset($this->activeTasks[$workerId][$taskId]);
                $promise->reject($reason);
                $this->checkGracefulShutdownCompletion();
            }
        );
    }

    /**
     * Automatically shut down the pool manager and release resources when garbage collected.
     */
    public function __destruct()
    {
        if (! $this->isShutdown) {
            $this->shutdown();
        }
    }
}
