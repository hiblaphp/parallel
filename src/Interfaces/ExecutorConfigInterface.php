<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

/**
 * Provides a fluent, immutable API for configuring the execution environment of child processes.
 *
 * Each method returns a new instance with the specified configuration, ensuring that
 * executor instances are safe to be passed around without side effects.
 */
interface ExecutorConfigInterface
{
    /**
     * Returns a new instance with a specific timeout for the task.
     *
     * If the task exceeds this duration in seconds, its promise will be rejected
     * with a TimeoutException and the underlying process will be terminated.
     *
     * @param int $seconds The maximum number of seconds to allow the task to run.
     *
     * @return static A new instance with the timeout configured.
     */
    public function withTimeout(int $seconds): static;

    /**
     * Returns a new instance with the execution timeout disabled.
     *
     * The task will be allowed to run indefinitely, bound only by system limits
     * or PHP's max_execution_time if not overridden in the worker.
     *
     * @return static A new instance without a timeout.
     */
    public function withoutTimeout(): static;

    /**
     * Returns a new instance with a specific memory limit for the worker process.
     *
     * @param string $limit The memory limit in a format accepted by ini_set() (e.g., '256M', '1G').
     *
     * @return static A new instance with the memory limit configured.
     */
    public function withMemoryLimit(string $limit): static;

    /**
     * Returns a new instance with the memory limit disabled for the worker process.
     *
     * This sets the memory limit to '-1'.
     *
     * @return static A new instance with an unlimited memory limit.
     */
    public function withUnlimitedMemory(): static;

    /**
     * Returns a new instance with a custom bootstrap file and/or callback.
     *
     * This is useful for loading framework containers, environment variables, or
     * any other setup required before the task can run.
     *
     * @param string $file The absolute path to a PHP file to require_once in the worker.
     * @param (callable(string $file): mixed)|null $callback An optional callback to run after the file is included. It receives the file path as an argument.
     *
     * @return static A new instance with the bootstrap logic configured.
     */
    public function withBootstrap(string $file, ?callable $callback = null): static;

    /**
     * Returns a new instance with a specific maximum nesting level.
     *
     * This controls how many times a parallel process can spawn another parallel process,
     * acting as a safeguard against accidental fork bombs. The default is 5.
     *
     * @param int $level The maximum allowed nesting depth (e.g., 2 means a child can spawn a grandchild).
     *
     * @return static A new instance with the max nesting level configured.
     */
    public function withMaxNestingLevel(int $level): static;
}
