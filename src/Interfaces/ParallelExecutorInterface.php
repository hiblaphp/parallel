<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for executing tasks in a new, isolated child process.
 *
 * Each call to `run()` or `spawn()` will create a fresh process that is
 * torn down after the task is complete.
 */
interface ParallelExecutorInterface extends ExecutorConfigInterface
{
    /**
     * Executes a task in a parallel process and returns a promise for its result.
     *
     * The parent process will wait for the returned promise to be resolved or
     * rejected. Output from the child process (echo, print) is streamed to the parent.
     *
     * @template TResult
     * @param callable(): TResult $callback The closure or callable to execute.
     * @return PromiseInterface<TResult> A promise that will be fulfilled with the return value of the task.
     */
    public function run(callable $callback): PromiseInterface;
}
