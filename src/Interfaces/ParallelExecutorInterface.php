<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

/**
 * Defines the contract for executing tasks in a new, isolated child process.
 * torn down after the task is complete.
 */
interface ParallelExecutorInterface extends ExecutorConfigInterface, ParallelRunnerInterface, MessagePassingInterface
{
}
