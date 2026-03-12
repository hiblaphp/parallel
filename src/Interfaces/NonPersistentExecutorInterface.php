<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Execution contract for one-off parallel tasks and background processes.
 */
interface NonPersistentExecutorInterface extends ExecutorConfigInterface
{
    /**
     * @template TResult
     * @param callable(): TResult $task
     * @return PromiseInterface<TResult>
     */
    public function run(callable $task): PromiseInterface;

    /**
     * @param callable $task
     * @return PromiseInterface<BackgroundProcess>
     */
    public function spawn(callable $task): PromiseInterface;
}
