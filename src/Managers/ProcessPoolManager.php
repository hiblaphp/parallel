<?php

declare(strict_types=1);

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Exceptions\NestingLimitException;
use Hibla\Parallel\Exceptions\PoolShutdownException;
use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Exceptions\TaskPayloadException;
use Hibla\Parallel\Exceptions\TimeoutException;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Internals\PersistentProcess;
use Hibla\Parallel\Traits\MessageHandlerComposer;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Exceptions\TimeoutException as PromiseTimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\Serializer\CallbackSerializationManager;

use SplQueue;

/**
 * @internal Manages a pool of persistent worker processes.
 */
final class ProcessPoolManager
{
    use MessageHandlerComposer;

    /**
     * @var SplQueue<PersistentProcess>
     */
    private SplQueue $idleWorkers;

    /**
     * @var SplQueue<array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string, 4: callable|null}>
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

    /**
     * @var Promise<void>|null
     */
    private ?Promise $shutdownPromise = null;

    private bool $isShutdown = false;

    private bool $isShuttingDownGracefully = false;

    /**
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null} $frameworkInfo
     * @param array<int, callable(WorkerMessage): void> $onMessageHandlers Registered pool-level handlers
     *        in registration order. All fire before the per-task handler. Each is wrapped in async()
     *        so await() inside is safe and none blocks the read loop fiber.
     * @param bool $spawnEagerly When true (default), workers are pre-spawned at construction.
     *        When false, workers are spawned on the first call to submit() — all workers
     *        boot concurrently on that first call, subsequent submits queue naturally.
     */
    public function __construct(
        private readonly int $size,
        private readonly ProcessSpawnHandler $spawnHandler,
        private readonly CallbackSerializationManager $serializer,
        private readonly array $frameworkInfo,
        private readonly ?string $memoryLimit,
        private readonly int $maxNestingLevel,
        private readonly array $onMessageHandlers = [],
        private readonly bool $spawnEagerly = true,
    ) {
        // Pre-flight check: prevent pool creation if exceeds the max nesting level
        $currentLevel = (int)((($env = getenv('DEFER_NESTING_LEVEL')) !== false) ? $env : 0);

        if ($currentLevel >= $this->maxNestingLevel) {
            throw new NestingLimitException(
                'Cannot create persistent pool: Already at maximum nesting level ' .
                    "{$currentLevel}/{$this->maxNestingLevel}. " .
                    "To increase this limit, configure 'max_nesting_level' in your hibla_parallel config file. " .
                    'Maximum safe limit is 10 levels.'
            );
        }

        $this->idleWorkers = new SplQueue();
        $this->taskQueue = new SplQueue();

        // Eager spawning — pre-spawn all workers immediately so the first task
        // is dispatched to an idle worker with zero additional latency.
        // Lazy spawning — defer worker creation until the first submit() call
        // so no processes are spawned if the pool is never actually used.
        if ($this->spawnEagerly) {
            $this->initialize();
        }
    }

    /**
     * Returns the number of worker processes currently alive in the pool.
     *
     * For eager pools this equals the configured pool size under normal conditions.
     * For lazy pools this reflects how many workers have been spawned so far.
     * The count may temporarily drop below the configured size while a crashed
     * worker's replacement is booting.
     *
     * @return int
     */
    public function getWorkerCount(): int
    {
        return \count($this->allWorkers);
    }

    /**
     * Returns the OS-level PIDs of all currently alive worker processes.
     *
     * Useful for monitoring, debugging, and correlating worker activity with
     * system-level process inspection tools. The array is unkeyed and unordered —
     * do not rely on index position to identify a specific worker.
     *
     * @return array<int, int>
     */
    public function getWorkerPids(): array
    {
        $pids = [];

        foreach ($this->allWorkers as $worker) {
            $pids[] = $worker->getPid();
        }

        return $pids;
    }

    /**
     * @template TValue
     * @param callable(): TValue $task
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task message handler.
     *        Fires before the pool-level handler. Both are wrapped in async() and run
     *        concurrently — no completion ordering is guaranteed between them.
     * @return PromiseInterface<TValue>
     */
    public function submit(callable $task, int $timeoutSeconds, string $sourceLocation = 'unknown', ?callable $onMessage = null): PromiseInterface
    {
        if ($this->isShutdown) {
            /** @var Promise<TValue> $promise */
            $promise = new Promise();
            $promise->reject(new PoolShutdownException('Cannot submit task to a shutdown pool.'));

            return $promise;
        }

        // Lazy spawning — initialize all workers on the first submit() call.
        // All workers boot concurrently so subsequent tasks queue naturally
        // and dispatch to idle workers as they become ready.
        // Note: bootstrap errors that would have surfaced at construction time
        // with eager spawning will surface here instead on the first submission.
        if (! $this->spawnEagerly) {
            $this->maybeSpawnWorker();
        }

        /** @var Promise<TValue> $promise */
        $promise = new Promise();

        $promise->onCancel(function () use ($promise) {
            foreach ($this->activeTasks as $workerId => $tasks) {
                foreach ($tasks as $activePromise) {
                    if ($activePromise === $promise) {
                        if (isset($this->allWorkers[$workerId])) {
                            // Terminating the worker triggers onCrash, which will natively
                            // respawn a replacement worker and unset active tasks.
                            $this->allWorkers[$workerId]->terminate();
                        }

                        return;
                    }
                }
            }

            if ($this->isShuttingDownGracefully) {
                $this->checkGracefulShutdownCompletion();
            }
        });

        // Safely find an idle worker (skipping any that crashed while idle)
        $worker = null;
        while (! $this->idleWorkers->isEmpty()) {
            $w = $this->idleWorkers->dequeue();
            if (isset($this->allWorkers[spl_object_id($w)])) {
                $worker = $w;

                break;
            }
        }

        if ($worker !== null) {
            $this->dispatch($worker, $task, $promise, $timeoutSeconds, $sourceLocation, $onMessage);
        } else {
            $this->taskQueue->enqueue([$task, $promise, $timeoutSeconds, $sourceLocation, $onMessage]);
        }

        return $promise;
    }

    /**
     * Gracefully shuts down the pool.
     *
     * This method will wait for all active tasks to complete before shutting down the pool.
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
            if (! $promise->isCancelled()) {
                $promise->reject(new PoolShutdownException('Pool was shut down before the task could be processed.'));
            }
        }

        foreach ($this->activeTasks as $workerId => $tasks) {
            foreach ($tasks as $promise) {
                if (! $promise->isCancelled()) {
                    $promise->reject(new PoolShutdownException('Pool was shut down before the task completed.'));
                }
            }
        }

        $this->allWorkers = [];
        $this->activeTasks = [];
        $this->idleWorkers = new SplQueue();

        if ($this->shutdownPromise !== null && ! $this->shutdownPromise->isFulfilled() && ! $this->shutdownPromise->isRejected()) {
            $this->shutdownPromise->resolve(null);
        }
    }

    private function initialize(): void
    {
        for ($i = 0; $i < $this->size; ++$i) {
            $this->spawnWorker();
        }
    }

    private function maybeSpawnWorker(): void
    {
        if (\count($this->allWorkers) < $this->size) {
            $this->spawnWorker();
        }
    }

    private function checkGracefulShutdownCompletion(): void
    {
        if (! $this->isShuttingDownGracefully) {
            return;
        }

        /** @var SplQueue<array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string, 4: callable|null}> $validQueue */
        $validQueue = new SplQueue();

        while (! $this->taskQueue->isEmpty()) {
            $item = $this->taskQueue->dequeue();
            if (! $item[1]->isCancelled()) {
                $validQueue->enqueue($item);
            }
        }
        $this->taskQueue = $validQueue;

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
            if (! $promise->isCancelled()) {
                $promise->reject($e);
            }
        }

        foreach ($this->activeTasks as $workerId => $tasks) {
            foreach ($tasks as $promise) {
                if (! $promise->isCancelled()) {
                    $promise->reject($e);
                }
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

            // Loop to skip cancelled queue items
            $taskToDispatch = null;
            while (! $this->taskQueue->isEmpty()) {
                /** @var array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string, 4: callable|null} $queued */
                $queued = $this->taskQueue->dequeue();
                if ($queued[1]->isCancelled()) {
                    continue; // Skip tasks cancelled before dispatch
                }
                $taskToDispatch = $queued;

                break;
            }

            if ($taskToDispatch !== null) {
                [$task, $promise, $timeout, $sourceLocation, $taskOnMessage] = $taskToDispatch;
                $this->dispatch($worker, $task, $promise, $timeout, $sourceLocation, $taskOnMessage);
            } else {
                if ($this->isShuttingDownGracefully) {
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

            if (isset($this->activeTasks[$wId]) && \count($this->activeTasks[$wId]) > 0) {
                foreach ($this->activeTasks[$wId] as $pendingPromise) {
                    if (! $pendingPromise->isCancelled()) {
                        $pendingPromise->reject(new ProcessCrashedException('Worker crashed or was forcefully closed while executing task.'));
                    }
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
                    new ProcessCrashedException('A persistent worker crashed during boot. Check logs for fatal errors (e.g., nesting limits or syntax errors).')
                );

                return;
            }

            // Always respawn to maintain the pool size
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
     * @param callable(WorkerMessage): void|null $onMessage Optional per-task handler
     */
    private function dispatch(PersistentProcess $worker, callable $task, Promise $promise, int $timeoutSeconds, string $sourceLocation, ?callable $onMessage = null): void
    {
        $taskId = bin2hex(random_bytes(16));

        try {
            $serializedTask = $this->serializer->serializeCallback($task);
        } catch (\Throwable $e) {
            if (! $promise->isCancelled()) {
                $promise->reject(new TaskPayloadException('Failed to serialize task payload: ' . $e->getMessage(), 0, $e));
            }
            $this->idleWorkers->enqueue($worker);

            return;
        }

        $payload = [
            'task_id' => $taskId,
            'serialized_callback' => $serializedTask,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($jsonPayload === false) {
            if (! $promise->isCancelled()) {
                $promise->reject(new TaskPayloadException('Failed to encode task payload: ' . json_last_error_msg()));
            }
            $this->idleWorkers->enqueue($worker);

            return;
        }

        $workerId = spl_object_id($worker);
        $this->activeTasks[$workerId][$taskId] = $promise;

        // Compose per-task and pool-level handlers into a single callable.
        // Per-task fires first (scheduled first onto the event loop), then
        // pool-level. Both are wrapped in async() inside PersistentProcess
        // so they run as independent fibers — neither blocks the read loop
        // and neither waits for the other to complete.
        $composedHandler = $this->composeMessageHandlers($this->onMessageHandlers, $onMessage);

        $executionPromise = $worker->submitTask($taskId, $jsonPayload, $sourceLocation, $composedHandler);

        if ($timeoutSeconds > 0) {
            $executionPromise = Promise::timeout($executionPromise, $timeoutSeconds);
        }

        $executionPromise->then(
            function ($value) use ($workerId, $taskId, $promise) {
                unset($this->activeTasks[$workerId][$taskId]);

                if (! $promise->isCancelled()) {
                    $promise->resolve($value);
                }

                $this->checkGracefulShutdownCompletion();
            },
            function ($reason) use ($workerId, $taskId, $promise, $timeoutSeconds) {
                unset($this->activeTasks[$workerId][$taskId]);

                if (! $promise->isCancelled()) {
                    if ($reason instanceof PromiseTimeoutException) {
                        $promise->reject(new TimeoutException("Process timeout after {$timeoutSeconds} seconds"));
                    } else {
                        $promise->reject($reason);
                    }
                }

                $this->checkGracefulShutdownCompletion();
            }
        );
    }

    public function __destruct()
    {
        if (! $this->isShutdown) {
            $this->shutdown();
        }
    }
}
