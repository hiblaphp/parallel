<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\Internals\BackgroundProcess;
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

    /**
     * Spawns a "fire-and-forget" background task.
     *
     * This creates a detached process that runs independently. The returned promise
     * resolves immediately with a `BackgroundProcess` instance, which can be used
     * to check the status of or terminate the process.
     *
     * @param callable(): mixed $callback The closure or callable to execute.
     * @return PromiseInterface<BackgroundProcess> A promise that resolves with the process handle.
     */
    public function spawn(callable $callback): PromiseInterface;
}
