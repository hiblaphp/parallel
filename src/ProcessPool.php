<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use function Hibla\async;
use function Hibla\await;

use Hibla\Cancellation\CancellationToken;
use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Process pool for running multiple tasks concurrently with controlled parallelism
 *
 * @template TResult
 */
class ProcessPool
{
    private int $maxProcess;

    /**
     * @var \Iterator<int|string, callable(): TResult>|null
     */
    private ?\Iterator $iterator = null;

    /**
     * @var array<int|string, TResult|TaskResult<TResult>>
     */
    private array $results = [];

    /**
     * @var array<int|string, Process<TResult>>
     */
    private array $runningProcesses = [];

    private ?\Throwable $firstError = null;

    /**
     * @param int $maxProcess Maximum number of parallel processes
     */
    public function __construct(int $maxProcess = 8)
    {
        $this->maxProcess = max(1, $maxProcess);
    }

    /**
     * Run tasks concurrently and return results. Throws on first error.
     *
     * The returned promise can be cancelled, which will terminate all running processes.
     *
     * @param array<int|string, callable(): TResult> $tasks Array of callable tasks to execute
     * @return PromiseInterface<array<int|string, TResult>> Promise that resolves with array of results
     * @throws \Throwable If any task fails or if cancelled
     *
     * @example
     * ```php
     * $pool = new ProcessPool(4);
     *
     * // Run and wait for all tasks
     * $results = await($pool->run($tasks));
     *
     * // With cancellation
     * $promise = $pool->run($tasks);
     * // Later: $promise->cancel(); // Terminates all running processes
     * ```
     */
    public function run(array $tasks): PromiseInterface
    {
        if ($tasks === []) {
            // Cast to the correct type to satisfy PHPStan
            /** @var array<int|string, TResult> $emptyResult */
            $emptyResult = [];
            return Promise::resolved($emptyResult);
        }

        $source = new CancellationTokenSource();

        return async(function () use ($tasks, $source) {
            $this->iterator = new \ArrayIterator($tasks);
            $this->iterator->rewind();
            $this->results = [];
            $this->runningProcesses = [];
            $this->firstError = null;

            $workers = [];
            $workerCount = min($this->maxProcess, \count($tasks));

            for ($i = 0; $i < $workerCount; ++$i) {
                $workers[] = $this->runWorker($source->token);
            }

            try {
                await(Promise::all($workers));

                // @phpstan-ignore-next-line
                if ($this->firstError !== null) {
                    throw $this->firstError;
                }

                ksort($this->results);

                /** @var array<int|string, TResult> */
                return $this->results;
            } finally {
                $this->terminateAllProcesses();
            }
        })->onCancel(function () use ($source) {
            $source->cancel();
            $this->terminateAllProcesses();
        });
    }

    /**
     * Run tasks concurrently and return settled results (never throws due to task errors).
     *
     * The returned promise can be cancelled, which will terminate all running processes.
     * Tasks that were already running will have their results marked as rejected.
     *
     * @param array<int|string, callable(): TResult> $tasks Array of callable tasks to execute
     * @return PromiseInterface<array<int|string, TaskResult<TResult>>> Promise that resolves with array of settled results
     * @throws \Throwable If cancelled
     *
     * @example
     * ```php
     * $pool = new ProcessPool(4);
     *
     * // Run and get all results (fulfilled or rejected)
     * $results = await($pool->runSettled($tasks));
     *
     * foreach ($results as $result) {
     *     if ($result->isFulfilled()) {
     *         echo "Success: " . $result->getValue() . "\n";
     *     } else {
     *         echo "Failed: " . $result->getReason() . "\n";
     *     }
     * }
     * ```
     */
    public function runSettled(array $tasks): PromiseInterface
    {
        if ($tasks === []) {
            // Cast to the correct type to satisfy PHPStan
            /** @var array<int|string, TaskResult<TResult>> $emptyResult */
            $emptyResult = [];
            return Promise::resolved($emptyResult);
        }

        $source = new CancellationTokenSource();

        return async(function () use ($tasks, $source) {
            $this->iterator = new \ArrayIterator($tasks);
            $this->iterator->rewind();
            $this->results = [];
            $this->runningProcesses = [];

            $workers = [];
            $workerCount = min($this->maxProcess, count($tasks));

            for ($i = 0; $i < $workerCount; ++$i) {
                $workers[] = $this->runWorkerSettled($source->token);
            }

            try {
                await(Promise::all($workers));
                ksort($this->results);

                /** @var array<int|string, TaskResult<TResult>> */
                return $this->results;
            } finally {
                $this->terminateAllProcesses();
            }
        })->onCancel(function () use ($source) {
            $source->cancel();
            $this->terminateAllProcesses();
        });
    }

    /**
     * Worker that processes tasks from the iterator (fail-fast mode)
     *
     * @param CancellationToken $cancellation Cancellation token
     * @return PromiseInterface<void> Promise that resolves when worker completes
     * @throws \Throwable If cancellation is requested
     */
    private function runWorker(CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator !== null && $this->iterator->valid()) {
                if ($this->firstError !== null) {
                    break;
                }

                $cancellation->throwIfCancelled();

                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    $process = ProcessManager::getGlobal()->spawnStreamedTask($task);
                    $this->runningProcesses[$key] = $process;

                    $this->results[$key] = await($process->getResult(), $cancellation);

                    unset($this->runningProcesses[$key]);
                } catch (\Throwable $e) {
                    unset($this->runningProcesses[$key]);
                    // @phpstan-ignore-next-line
                    if ($this->firstError === null) {
                        $this->firstError = $e;
                    }
                }
            }
        });
    }

    /**
     * Worker that processes tasks from the iterator (settled mode)
     *
     * @param CancellationToken $cancellation Cancellation token
     * @return PromiseInterface<void> Promise that resolves when worker completes
     * @throws \Throwable If cancellation is requested
     */
    private function runWorkerSettled(CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator !== null && $this->iterator->valid()) {
                $cancellation->throwIfCancelled();

                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    $process = ProcessManager::getGlobal()->spawnStreamedTask($task);
                    $this->runningProcesses[$key] = $process;

                    $value = await($process->getResult(), $cancellation);
                    $this->results[$key] = TaskResult::fulfilled($value);

                    unset($this->runningProcesses[$key]);
                } catch (\Throwable $e) {
                    unset($this->runningProcesses[$key]);
                    $this->results[$key] = TaskResult::rejected($e->getMessage());
                }
            }
        });
    }

    /**
     * Terminate all currently running processes
     *
     * @return void
     */
    private function terminateAllProcesses(): void
    {
        foreach ($this->runningProcesses as $process) {
            try {
                $process->terminate();
            } catch (\Throwable) {
                // Ignore errors during termination
            }
        }

        $this->runningProcesses = [];
    }
}