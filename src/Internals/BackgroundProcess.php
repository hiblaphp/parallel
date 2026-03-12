<?php

declare(strict_types=1);

namespace Hibla\Parallel\Internals;

/**
 * Represents a fire-and-forget background process.
 * Does not support retrieving results or streaming output.
 */
final class BackgroundProcess
{
    /**
     * @param int $pid The process ID
     */
    public function __construct(
        private readonly int $pid,
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
    }

    /**
     * Check if the process is currently running.
     *
     * @return bool True if process is running, false otherwise
     */
    public function isRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$this->pid}\" 2>nul";
            $output = shell_exec($cmd);

            return \is_string($output) && strpos($output, (string)$this->pid) !== false;
        }

        return posix_kill($this->pid, 0);
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
