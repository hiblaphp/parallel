<?php

declare(strict_types=1);

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Exceptions\NestingLimitException;
use Hibla\Parallel\Exceptions\PoolShutdownException;
use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Exceptions\RespawnRateLimitException;
use Hibla\Parallel\Exceptions\TaskPayloadException;
use Hibla\Parallel\Exceptions\TimeoutException;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Internals\PersistentProcess;
use Hibla\Parallel\Traits\MessageHandlerComposer;
use Hibla\Parallel\Utilities\ProcessKiller;
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
     * @var array<int, array<string, Promise<mixed>>>
     */
    private array $activeTasks = [];

    /**
     * @var Promise<void>|null
     */
    private ?Promise $shutdownPromise = null;

    /**
     * @var Promise<void>|null
     */
    private ?Promise $bootPromise = null;

    /**
     * @var int<1, max>|null
     */
    private readonly ?int $maxExecutionsPerWorker;

    /**
     * @var int<1, max>|null
     */
    private readonly ?int $maxRestartPerSecond;

    /**
     * @var list<int>
     */
    private array $respawnTimestamps = [];

    private int $initialReadyCount = 0;

    private bool $bootCompleted = false;

    private bool $isShutdown = false;

    private bool $isShuttingDownGracefully = false;

    /**
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null} $frameworkInfo
     * @param array<int, callable(WorkerMessage): void> $onMessageHandlers
     * @param int<1, max>|null $maxExecutionsPerWorker
     * @param int<1, max>|null $maxRestartPerSecond
     * @param callable(): void|null $onWorkerRespawn
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
        ?int $maxExecutionsPerWorker = null,
        ?int $maxRestartPerSecond = null,
        /** @var callable(): void|null */
        private $onWorkerRespawn = null,
        private readonly ?int $timeoutSeconds = null,
    ) {
        $currentLevel = (int)((($env = getenv('DEFER_NESTING_LEVEL')) !== false) ? $env : 0);

        if ($currentLevel >= $this->maxNestingLevel) {
            throw new NestingLimitException(
                'Cannot create persistent pool: Already at maximum nesting level ' .
                    "{$currentLevel}/{$this->maxNestingLevel}. " .
                    "To increase this limit, configure 'max_nesting_level' in your hibla_parallel config file. " .
                    'Maximum safe limit is 10 levels.'
            );
        }

        $this->maxExecutionsPerWorker = $maxExecutionsPerWorker;
        $this->maxRestartPerSecond = $maxRestartPerSecond;

        $this->idleWorkers = new SplQueue();
        $this->taskQueue = new SplQueue();

        if ($this->spawnEagerly) {
            /** @var Promise<void> $bootPromise */
            $bootPromise = new Promise();
            $this->bootPromise = $bootPromise;
            $this->initialize();
        } else {
            $this->bootCompleted = true;
        }
    }

    /**
     * @return PromiseInterface<void>
     */
    public function waitUntilReady(): PromiseInterface
    {
        if ($this->bootCompleted || $this->bootPromise === null) {
            return Promise::resolved();
        }

        return $this->bootPromise;
    }

    public function getWorkerCount(): int
    {
        return \count($this->allWorkers);
    }

    /**
     * @return array<int, int>
     */
    public function getWorkerPids(): array
    {
        $pids = [];

        foreach ($this->allWorkers as $worker) {
            $pids[] = $worker->getWorkerPid();
        }

        return $pids;
    }

    /**
     * @template TValue
     * @param callable(): TValue $task
     * @param callable(WorkerMessage): void|null $onMessage
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

    public function shutdown(): void
    {
        if ($this->isShutdown && ! $this->isShuttingDownGracefully) {
            return;
        }

        $this->isShutdown = true;
        $this->isShuttingDownGracefully = false;

        if (! $this->bootCompleted && $this->bootPromise !== null) {
            $this->bootCompleted = true;
            if (! $this->bootPromise->isFulfilled() && ! $this->bootPromise->isRejected()) {
                $this->bootPromise->reject(new PoolShutdownException('Pool was shut down before all workers finished booting.'));
            }
        }

        // BATCH ASYNC KILL OPTIMIZATION
        // This launches the detached killer in the background instantly (0.001s).
        $pids = [];
        foreach ($this->allWorkers as $worker) {
            $pids[] = $worker->getPid();
        }

        if (\count($pids) > 0) {
            ProcessKiller::killTreesAsync($pids);
        }

        // Signal all workers but DO NOT perform individual OS kills
        foreach ($this->allWorkers as $worker) {
            $worker->signalTerminate(false);
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

        // Close streams, but leave the Windows process handles open so the tree
        // stays intact for the background killer.
        foreach ($this->allWorkers as $worker) {
            $worker->cleanupResources(PHP_OS_FAMILY !== 'Windows');
        }

        $this->allWorkers = [];
        $this->activeTasks = [];
        $this->idleWorkers = new SplQueue();

        if ($this->shutdownPromise !== null && ! $this->shutdownPromise->isFulfilled() && ! $this->shutdownPromise->isRejected()) {
            $this->shutdownPromise->resolve(null);
        }
    }

    private function recordRespawn(): void
    {
        $this->respawnTimestamps[] = (int) hrtime(true);
    }

    private function isRespawnRateLimitExceeded(): bool
    {
        if ($this->maxRestartPerSecond === null) {
            return false;
        }

        $now = hrtime(true);
        $cutoff = $now - 1_000_000_000;

        $this->respawnTimestamps = array_values(
            array_filter($this->respawnTimestamps, static fn(int $t): bool => $t > $cutoff)
        );

        return \count($this->respawnTimestamps) >= $this->maxRestartPerSecond;
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

        foreach ($this->activeTasks as $tasks) {
            if (\count($tasks) > 0) {
                return;
            }
        }

        // All tasks completed, initiate async background shutdown
        $pids = [];
        foreach ($this->allWorkers as $worker) {
            $pids[] = $worker->getPid();
        }

        if (\count($pids) > 0) {
            ProcessKiller::killTreesAsync($pids);
        }

        foreach ($this->allWorkers as $worker) {
            $worker->signalTerminate(false);
        }

        foreach ($this->allWorkers as $worker) {
            $worker->cleanupResources(PHP_OS_FAMILY !== 'Windows');
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

        if (! $this->bootCompleted && $this->bootPromise !== null) {
            $this->bootCompleted = true;
            if (! $this->bootPromise->isFulfilled() && ! $this->bootPromise->isRejected()) {
                $this->bootPromise->reject($e);
            }
        }

        $pids = [];
        foreach ($this->allWorkers as $worker) {
            $pids[] = $worker->getPid();
        }

        if (\count($pids) > 0) {
            ProcessKiller::killTreesAsync($pids);
        }

        foreach ($this->allWorkers as $worker) {
            $worker->signalTerminate(false);
        }

        $workersToCleanup = $this->allWorkers;
        $this->allWorkers  = [];

        while (! $this->taskQueue->isEmpty()) {
            $taskData = $this->taskQueue->dequeue();
            /** @var Promise<mixed> $promise */
            $promise = $taskData[1];
            if (! $promise->isCancelled()) {
                $promise->reject($e);
            }
        }

        foreach ($this->activeTasks as $tasks) {
            foreach ($tasks as $promise) {
                if (! $promise->isCancelled()) {
                    $promise->reject($e);
                }
            }
        }

        foreach ($workersToCleanup as $worker) {
            $worker->cleanupResources(PHP_OS_FAMILY !== 'Windows');
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
            $this->maxNestingLevel,
            $this->maxExecutionsPerWorker,
            $this->timeoutSeconds,
        );

        $workerId = spl_object_id($process);
        $this->allWorkers[$workerId] = $process;
        $this->activeTasks[$workerId] = [];
        $workerIsReady = false;

        $onReady = function (PersistentProcess $worker) use (&$workerIsReady): void {
            $isFirstReady = ! $workerIsReady;
            $workerIsReady = true;

            if ($isFirstReady && ! $this->bootCompleted && $this->bootPromise !== null) {
                $this->initialReadyCount++;
                if ($this->initialReadyCount >= $this->size) {
                    $this->bootCompleted = true;
                    $this->bootPromise->resolve(null);
                }
            }

            if ($this->isShutdown && ! $this->isShuttingDownGracefully) {
                $worker->terminate();
                unset($this->allWorkers[spl_object_id($worker)]);

                return;
            }

            $taskToDispatch = null;
            while (! $this->taskQueue->isEmpty()) {
                /** @var array{0: callable(): mixed, 1: Promise<mixed>, 2: int, 3: string, 4: callable|null} $queued */
                $queued = $this->taskQueue->dequeue();
                if ($queued[1]->isCancelled()) {
                    continue;
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

            if ($this->isRespawnRateLimitExceeded()) {
                $this->shutdownDueToFatalError(
                    new RespawnRateLimitException(
                        "Pool respawn rate limit exceeded: more than {$this->maxRestartPerSecond} worker(s) " .
                            'restarted within the last second. This usually indicates a crash loop caused by ' .
                            'a bad task payload, a broken bootstrap file, or an unhandled fatal error inside ' .
                            'worker code. The pool has been shut down to prevent resource exhaustion.'
                    )
                );

                return;
            }

            $this->recordRespawn();

            if (\count($this->allWorkers) < $this->size) {
                $this->spawnWorker();

                if ($this->onWorkerRespawn !== null) {
                    \Hibla\async($this->onWorkerRespawn);
                }
            }
        };

        $process->startReadLoop($onReady, $onCrash);
    }

    /**
     * @template TValue
     * @param callable(): TValue $task
     * @param Promise<TValue> $promise
     * @param callable(WorkerMessage): void|null $onMessage
     */
    private function dispatch(PersistentProcess $worker, callable $task, Promise $promise, int $timeoutSeconds, string $sourceLocation, ?callable $onMessage = null): void
    {
        $taskId = bin2hex(random_bytes(16));

        try {
            $serializedTask = $this->serializer->serializeCallback($task);
        } catch (\Throwable $e) {
            if (! $promise->isCancelled()) {
                $message = $e->getMessage();

                if ($e instanceof \TypeError || str_contains($message, 'getCallableForm') || str_contains($message, 'serialize')) {
                    $promise->reject(new TaskPayloadException(
                        "Failed to serialize task payload: The closure likely captures an unserializable object (e.g., ProcessPool, Stream, or PDO). Ensure you are not passing active OS resources into the closure via 'use (...)'. Underlying error: " . $message
                    ));
                } else {
                    $promise->reject(new TaskPayloadException('Failed to serialize task payload: ' . $message));
                }
            }
            $this->idleWorkers->enqueue($worker);

            return;
        }

        $payload = [
            'task_id' => $taskId,
            'serialized_callback' => $serializedTask,
            'timeout_seconds' => $timeoutSeconds,
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
            function ($reason) use ($worker, $workerId, $taskId, $promise, $timeoutSeconds) {
                unset($this->activeTasks[$workerId][$taskId]);

                if ($reason instanceof PromiseTimeoutException) {
                    if (isset($this->allWorkers[$workerId])) {
                        $worker->terminate();
                    }
                }

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
