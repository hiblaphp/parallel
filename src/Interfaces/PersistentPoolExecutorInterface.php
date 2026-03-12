<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Execution contract for persistent worker pool executors.
 */
interface PersistentPoolExecutorInterface extends ExecutorConfigInterface
{
    /**
     * @template TResult
     * @param callable(): TResult $task
     * @return PromiseInterface<TResult>
     */
    public function run(callable $task): PromiseInterface;

    public function shutdown(): void;
}
