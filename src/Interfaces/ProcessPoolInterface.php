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
interface ProcessPoolInterface extends ExecutorConfigInterface, MessagePassingInterface, ParallelRunnerInterface
{
    /**
     * Returns a new instance configured to spawn workers lazily on the first
     * task submission rather than eagerly at pool construction time.
     *
     * **Eager spawning (default):**
     * - Workers are pre-spawned at pool construction
     * - First task dispatched immediately to an idle worker — zero latency
     * - Bootstrap errors surface immediately at construction time
     * - Best for sustained workloads where the pool is always kept busy
     *
     * **Lazy spawning:**
     * - Workers are spawned on the first call to run()
     * - First tasks incur worker boot latency (~50-100ms per worker)
     * - Bootstrap errors surface on first task submission rather than construction
     * - Best for conditional or short-lived pools where workers may not be needed
     *
     * @return static A new instance configured for lazy worker spawning.
     */
    public function withLazySpawning(): static;

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

    /**
     * Returns the number of worker processes currently alive in the pool.
     *
     * For eager pools this equals the configured pool size under normal conditions.
     * For lazy pools this reflects how many workers have been spawned so far.
     * The count may temporarily drop below the configured size while a crashed
     * worker's replacement is booting.
     *
     * @return int
     */
    public function getWorkerCount(): int;

    /**
     * Returns the OS-level PIDs of all currently alive worker processes.
     *
     * Useful for monitoring, debugging, and correlating worker activity with
     * system-level process inspection tools. The array is unkeyed and unordered —
     * do not rely on index position to identify a specific worker.
     *
     * @return array<int, int>
     */
    public function getWorkerPids(): array;
}
