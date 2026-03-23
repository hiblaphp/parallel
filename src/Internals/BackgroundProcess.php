<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

use Hibla\Parallel\Utilities\ProcessKiller;

/**
 * @internal
 *
 * Represents a fire-and-forget background process.
 * Does not support retrieving results or streaming output.
 */
final class BackgroundProcess
{
    private bool $closed = false;

    /**
     * @param int $pid The process ID
     * @param mixed $processResource The process resource handle from proc_open.
     *        Null when the resource is unavailable — isRunning() falls back to
     *        posix_kill() on Unix and tasklist on Windows in that case.
     */
    public function __construct(
        private readonly int $pid,
        private readonly mixed $processResource = null,
    ) {
    }

    /**
     * Terminate the background process forcefully.
     *
     * On Windows, proc_open() is called with bypass_shell => true in
     * ProcessSpawnHandler, so the resource maps directly to the PHP worker
     * with no cmd.exe wrapper. proc_terminate() calls TerminateProcess()
     * internally which is non-blocking and returns immediately — no external
     * process (taskkill/cmd.exe) needs to be spawned.
     *
     * On Unix, pkill/kill cover the child tree since proc_terminate() only
     * signals the direct process.
     *
     * @return void
     */
    public function terminate(): void
    {
        if ($this->closed) {
            return;
        }

        ProcessKiller::killTree($this->pid, $this->processResource);
    }

    /**
     * Check if the process is currently running.
     *
     * On Unix, uses proc_get_status() for an efficient in-process check that
     * does not require the posix extension, consistent with Process.php.
     * On Windows, falls back to tasklist since proc_get_status() is unreliable
     * for processes opened with socket descriptors on that platform.
     *
     * @return bool True if process is running, false otherwise
     */
    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = shell_exec("tasklist /FI \"PID eq {$this->pid}\" 2>nul");
            $running = \is_string($output) && strpos($output, (string)$this->pid) !== false;
        } else {
            if (\is_resource($this->processResource)) {
                $status = proc_get_status($this->processResource);
                $running = $status['running'];
            } elseif (\function_exists('posix_kill')) {
                $running = posix_kill($this->pid, 0);
            } else {
                $output = shell_exec("ps -p {$this->pid} 2>/dev/null");
                $running = \is_string($output) && strpos($output, (string)$this->pid) !== false;
            }
        }

        return $running;
    }

    /**
     * Get the process ID.
     *
     * @return int The process ID
     */
    public function getPid(): int
    {
        return $this->pid;
    }
}