<?php

declare(strict_types=1);

namespace Hibla\Parallel\Utilities;

/**
 * @internal
 *
 * Cross-platform process tree kill utility.
 *
 * Handles recursive termination of a process and all its descendants,
 * addressing the orphan problem where killing a parent leaves grandchildren
 * running (e.g. sub-workers spawned inside a parallel() worker).
 */
final class ProcessKiller
{
    /**
     * Kill a process and its entire descendant tree.
     *
     * On Windows, taskkill /F /T is run synchronously BEFORE proc_close().
     * proc_terminate() cannot be called first — it kills the parent instantly,
     * breaking the parent-child link in the Windows process table so taskkill /T
     * can no longer enumerate and reach the grandchildren (nested workers).
     * By running taskkill /T first (blocking), the full tree is killed before
     * proc_close() is called, which then returns immediately since the process
     * is already dead.
     *
     * On Linux, recursively walks the process tree using pgrep before killing
     * the parent — children must be killed first or they become orphans when
     * the parent dies and get re-parented to init.
     *
     * @param int $pid The process ID to kill
     * @param mixed $processResource The proc_open resource handle, if available
     */
    public static function killTree(int $pid, mixed $processResource = null): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // taskkill /T must run BEFORE the parent process dies — once the parent
            // is dead its children are orphaned and re-parented, breaking the tree
            // walk. Running synchronously ensures the full tree is dead before
            // proc_close() is called, so proc_close() returns immediately.
            exec("taskkill /F /T /PID {$pid} >nul 2>nul");

            // Signal the resource handle after taskkill has already killed the
            // process — this is a no-op for an already-dead process but ensures
            // the proc resource is properly marked as terminated for proc_close().
            if (\is_resource($processResource)) {
                @proc_terminate($processResource);
            }
        } else {
            self::killTreeLinux($pid);
        }
    }

    /**
     * Recursively kill a process tree on Linux, bottom-up.
     *
     * Uses pgrep to find direct children, recurses into each before killing
     * the parent. This ensures grandchildren (e.g. sub-workers spawned inside
     * a worker) are terminated before their parent disappears.
     *
     * @param int $pid Root of the subtree to kill
     */
    private static function killTreeLinux(int $pid): void
    {
        // Find direct children before killing the parent — once the parent
        // dies the children get re-parented and pgrep -P would miss them.
        $output = shell_exec("pgrep -P {$pid} 2>/dev/null");

        if (\is_string($output) && $output !== '') {
            foreach (explode("\n", trim($output)) as $childPid) {
                if (ctype_digit($childPid) && (int)$childPid > 0) {
                    self::killTreeLinux((int)$childPid);
                }
            }
        }

        exec("kill -9 {$pid} 2>/dev/null");
    }
}