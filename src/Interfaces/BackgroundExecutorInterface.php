<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Defines the contract for spawning fire-and-forget background processes.
 */
interface BackgroundExecutorInterface extends ExecutorConfigInterface
{
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
