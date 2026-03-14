<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

/**
 * @internal
 *
 * Static registry that tracks the current worker execution context.
 *
 * Used by emit() to determine whether message passing is available and
 * to retrieve the current task ID for persistent worker frame routing.
 * Safe to use as a static registry because worker scripts are isolated
 * single-process executables — there is no shared state across requests.
 */
final class WorkerContext
{
    private static bool $isWorker = false;

    private static bool $isBackgroundWorker = false;

    private static ?string $currentTaskId = null;

    /**
     * Mark the current process as a streamed worker (worker.php).
     * Enables emit() to write MESSAGE frames to stdout.
     *
     * @return void
     */
    public static function markAsWorker(): void
    {
        self::$isWorker = true;
        self::$isBackgroundWorker = false;
    }

    /**
     * Mark the current process as a background worker (worker_background.php).
     * Causes emit() to silently no-op since stdout is /dev/null.
     *
     * @return void
     */
    public static function markAsBackgroundWorker(): void
    {
        self::$isWorker = false;
        self::$isBackgroundWorker = true;
    }

    /**
     * Set the task ID for the currently executing task.
     * Used by persistent workers to tag MESSAGE frames for correct
     * routing back to the originating task's handler on the parent side.
     *
     * @param string|null $taskId
     * @return void
     */
    public static function setCurrentTaskId(?string $taskId): void
    {
        self::$currentTaskId = $taskId;
    }

    /**
     * Returns true if the current process is a streamed worker
     * with an active stdout channel for message passing.
     *
     * @return bool
     */
    public static function isWorker(): bool
    {
        return self::$isWorker;
    }

    /**
     * Returns true if the current process is a fire-and-forget background
     * worker where message passing is unavailable.
     *
     * @return bool
     */
    public static function isBackgroundWorker(): bool
    {
        return self::$isBackgroundWorker;
    }

    /**
     * Returns the task ID of the currently executing task, or null if
     * running in a one-off streamed worker with no task ID.
     *
     * @return string|null
     */
    public static function getCurrentTaskId(): ?string
    {
        return self::$currentTaskId;
    }
}
