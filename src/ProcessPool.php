<?php

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationToken;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use function Hibla\async;
use function Hibla\await;

/**
 * A true process pool that manages a limited number of concurrent workers
 * to execute a queue of tasks.
 */
class ProcessPool
{
    private int $maxConcurrency;
    private ?\Iterator $iterator = null;
    private array $results = [];
    private ?\Throwable $firstError = null;

    public function __construct(int $maxConcurrency = 8)
    {
        $this->maxConcurrency = max(1, $maxConcurrency);
    }

    /**
     * Executes an array of callables with a concurrency limit.
     * The promise resolves with an array of results, preserving keys.
     * If any task fails, the promise rejects, and no further tasks are started.
     *
     * @param array<string|int, callable> $tasks Associative array of tasks to run.
     * @param CancellationToken|null $cancellation Token to cancel the entire pool operation.
     * @return PromiseInterface<array>
     */
    public function run(array $tasks, ?CancellationToken $cancellation = null): PromiseInterface
    {
        if (empty($tasks)) {
            return Promise::resolved([]);
        }

        return async(function () use ($tasks, $cancellation) {
            $this->iterator = new \ArrayIterator($tasks);
            $this->iterator->rewind();
            $this->results = [];
            $this->firstError = null;
            
            $workers = [];
            $workerCount = min($this->maxConcurrency, count($tasks));

            for ($i = 0; $i < $workerCount; ++$i) {
                $workers[] = $this->runWorker($cancellation);
            }

            await(Promise::all($workers));

            if ($this->firstError) {
                throw $this->firstError;
            }

            ksort($this->results);
            return $this->results;
        });
    }

    /**
     * Executes tasks and returns a settled result for each, never rejecting.
     *
     * @param array<string|int, callable> $tasks Associative array of tasks to run.
     * @param CancellationToken|null $cancellation Token to cancel the entire pool operation.
     * @return PromiseInterface<array>
     */
    public function runSettled(array $tasks, ?CancellationToken $cancellation = null): PromiseInterface
    {
         if (empty($tasks)) {
            return Promise::resolved([]);
        }

        return async(function () use ($tasks, $cancellation) {
            $this->iterator = new \ArrayIterator($tasks);
            $this->iterator->rewind();
            $this->results = [];

            $workers = [];
            $workerCount = min($this->maxConcurrency, count($tasks));
            
            for ($i = 0; $i < $workerCount; ++$i) {
                $workers[] = $this->runWorkerSettled($cancellation);
            }

            await(Promise::all($workers));

            ksort($this->results);
            return $this->results;
        });
    }

    /**
     * The logic for a single worker coroutine that rejects on the first error.
     */
    private function runWorker(?CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator->valid() && $this->firstError === null) {
                $cancellation?->throwIfCancelled();

                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    /** @var Process $process */
                    $process = await(Process::spawn($task), $cancellation);
                
                    $this->results[$key] = await($process->await(), $cancellation);
                } catch (\Throwable $e) {
                    if ($this->firstError === null) {
                        $this->firstError = $e;
                    }
                }
            }
        });
    }
    
    /**
     * The logic for a single worker coroutine that settles all tasks.
     */
    private function runWorkerSettled(?CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator->valid()) {
                $cancellation?->throwIfCancelled();

                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    /** @var Process $process */
                    $process = await(Process::spawn($task), $cancellation);
                    $value = await($process->await(), $cancellation);
                    $this->results[$key] = ['status' => 'fulfilled', 'value' => $value];
                } catch (\Throwable $e) {
                     $this->results[$key] = ['status' => 'rejected', 'reason' => $e->getMessage()];
                }
            }
        });
    }
}