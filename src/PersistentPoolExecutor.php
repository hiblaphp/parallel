<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Managers\ProcessPool;
use Hibla\Promise\Interfaces\PromiseInterface;

final class PersistentPoolExecutor
{
    private ProcessPool $pool;
    private int $timeoutSeconds = 60;
    private bool $unlimitedTimeout = false;

    /**
     * @internal This should be instantiated via ParallelExecutor::withPersistentPool()
     *
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null} $frameworkInfo
     */
    public function __construct(
        int $size,
        array $frameworkInfo,
        ?string $memoryLimit,
        int $maxNestingLevel
    ) {
        $manager = ProcessManager::getGlobal();
        $this->pool = new ProcessPool(
            $size,
            $manager->getSpawnHandler(),
            $manager->getSerializer(),
            $manager->getSystemUtils(),
            $frameworkInfo,
            $memoryLimit,
            $maxNestingLevel
        );
    }

    /**
     * Set the maximum execution time in seconds for tasks submitted after this call.
     */
    public function withTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->timeoutSeconds = $seconds;
        $clone->unlimitedTimeout = false;

        return $clone;
    }

    /**
     * Allows tasks to run without any time limit.
     */
    public function withoutTimeout(): self
    {
        $clone = clone $this;
        $clone->unlimitedTimeout = true;

        return $clone;
    }

    /**
     * Executes the given task in the persistent pool.
     *
     * @template TResult
     * @param callable(): TResult $task
     * @return PromiseInterface<TResult>
     */
    public function run(callable $task): PromiseInterface
    {
        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        return $this->pool->submit($task, $finalTimeout);
    }

    /**
     * Gracefully shuts down all worker processes in the pool.
     */
    public function shutdown(): void
    {
        $this->pool->shutdown();
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
