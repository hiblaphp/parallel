<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Cancellation\CancellationToken;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use function Hibla\async;
use function Hibla\await;

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

    public function run(array $tasks, ?CancellationToken $cancellation = null): PromiseInterface
    {
        if (empty($tasks)) return Promise::resolved([]);

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

            if ($this->firstError) throw $this->firstError;
            ksort($this->results);
            return $this->results;
        });
    }

    public function runSettled(array $tasks, ?CancellationToken $cancellation = null): PromiseInterface
    {
         if (empty($tasks)) return Promise::resolved([]);

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

    private function runWorker(?CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator->valid() && $this->firstError === null) {
                $cancellation?->throwIfCancelled();
                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    $process = ProcessManager::getGlobal()->spawnStreamedTask($task);
                    $this->results[$key] = await($process->getResult(), $cancellation);
                } catch (\Throwable $e) {
                    if ($this->firstError === null) $this->firstError = $e;
                }
            }
        });
    }
    
    private function runWorkerSettled(?CancellationToken $cancellation): PromiseInterface
    {
        return async(function () use ($cancellation) {
            while ($this->iterator->valid()) {
                $cancellation?->throwIfCancelled();
                $key = $this->iterator->key();
                $task = $this->iterator->current();
                $this->iterator->next();

                try {
                    $process = ProcessManager::getGlobal()->spawnStreamedTask($task);
                    $value = await($process->getResult(), $cancellation);
                    $this->results[$key] = ['status' => 'fulfilled', 'value' => $value];
                } catch (\Throwable $e) {
                     $this->results[$key] = ['status' => 'rejected', 'reason' => $e->getMessage()];
                }
            }
        });
    }
}