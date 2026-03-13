<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for a persistent pool of reusable worker processes.
 *
 * A pool maintains a set number of active worker processes, distributing tasks
 * among them to reduce the overhead of process creation for frequent, short-lived tasks.
 */
interface ProcessPoolInterface extends ExecutorConfigInterface
{
    /**
     * Submits a task to the pool for execution.
     *
     * The task will be added to a queue and executed by the next available worker.
     *
     * @template TResult
     * @param callable(): TResult $callback The closure or callable to execute.
     * @return PromiseInterface<TResult> A promise that will be fulfilled with the return value of the task.
     */
    public function run(callable $callback): PromiseInterface;

    /**
     * Gracefully shuts down the pool asynchronously.
     *
     * Stops accepting new tasks, but allows all currently queued and executing
     * tasks to finish before terminating the underlying worker processes.
     *
     * @return PromiseInterface<void> A promise that resolves when all workers have safely terminated.
     */
    public function shutdownAsync(): PromiseInterface;

    /**
     * Shuts down the pool forcefully, terminating all active worker processes immediately.
     *
     * Any tasks currently in the queue or executing will be rejected. This method should
     * always be called when the pool is no longer needed to ensure clean resource cleanup.
     * The destructor will attempt to call this, but explicit calls are recommended.
     */
    public function shutdown(): void;
}
