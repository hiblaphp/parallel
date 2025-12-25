<?php

namespace Hibla\Parallel\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Represents a spawned background process.
 * 
 * @template TResult
 */
interface ProcessInterface
{
    /**
     * Waits for the process to complete and returns its result.
     *
     * @param int $timeoutSeconds Maximum time to wait for the process to complete, in seconds.
     * @return PromiseInterface<TResult> A promise that resolves with the task's result or rejects on timeout/error.
     * @throws \RuntimeException If the task times out or fails.
     */
    public function getResult(int $timeoutSeconds = 60): PromiseInterface;

    /**
     * Cancels the process immediately and all its child processes.
     * 
     * This will send SIGKILL on Unix systems or use taskkill on Windows.
     * The process status will be updated to CANCELLED.
     *
     * @return void
     */
    public function terminate(): void;

    /**
     * Checks if the process is still running.
     *
     * @return bool True if the process is currently running, false otherwise.
     */
    public function isRunning(): bool;

    /**
     * Gets the process ID of the spawned process.
     *
     * @return int The operating system process ID.
     */
    public function getPid(): int;

    /**
     * Gets the unique task identifier for this process.
     *
     * @return string The task ID in format: defer_YYYYMMDD_HHMMSS_<unique_hash>
     */
    public function getTaskId(): string;
}