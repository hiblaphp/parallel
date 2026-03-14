<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

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
     * @return void
     */
    public function terminate(): void
    {
        if ($this->isRunning()) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$this->pid} 2>nul");
            } else {
                exec("pkill -9 -P {$this->pid} 2>/dev/null; kill -9 {$this->pid} 2>/dev/null");
            }
        }

        $this->close();
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

        if (! $running) {
            $this->close();
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

    /**
     * Close the process resource handle and mark this instance as closed.
     *
     * Safe to call multiple times — guarded by the closed flag so subsequent
     * calls are no-ops. proc_close() will not block here because by the time
     * it is called the child has either been killed or exited naturally, so it
     * simply reaps the zombie entry from the OS process table.
     *
     * @return void
     */
    private function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (\is_resource($this->processResource)) {
            @proc_close($this->processResource);
        }
    }

    /**
     * Release the process resource handle if it was never explicitly closed.
     * This is a safety net for cases where terminate() was never called and
     * isRunning() never observed the process exit on its own.
     */
    public function __destruct()
    {
        $this->close();
    }
}
