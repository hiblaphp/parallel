<?php

namespace Hibla\Parallel;

/**
 * Represents a fire-and-forget background process.
 * Does not support retrieving results or streaming output.
 */
final class BackgroundProcess
{
    /**
     * @param string $taskId The task ID
     * @param int $pid The Process ID
     * @param string|null $statusFilePath Path to the status file (if logging enabled)
     * @param bool $loggingEnabled Whether logging is active
     */
    public function __construct(
        private readonly string $taskId,
        private readonly int $pid,
        private readonly ?string $statusFilePath = null,
        private readonly bool $loggingEnabled = false
    ) {}

    /**
     * Terminate the background process forcefully
     *
     * @return void
     */
    public function terminate(): void
    {
        if ($this->isRunning()) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$this->pid} 2>nul");
            } else {
                exec("kill -9 {$this->pid} 2>/dev/null");
            }
        }

        $this->updateStatusOnTermination();
    }

    /**
     * Check if the process is currently running
     *
     * @return bool True if process is running, false otherwise
     */
    public function isRunning(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "tasklist /FI \"PID eq {$this->pid}\" 2>nul";
            $output = shell_exec($cmd);
            return $output !== null && strpos($output, (string)$this->pid) !== false;
        }

        return posix_kill($this->pid, 0);
    }

    /**
     * Get the process ID
     *
     * @return int The process ID
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * Get the task ID
     *
     * @return string The task ID
     */
    public function getTaskId(): string
    {
        return $this->taskId;
    }

    /**
     * Update the status file when process is terminated
     *
     * @return void
     */
    private function updateStatusOnTermination(): void
    {
        if (!$this->loggingEnabled || !$this->statusFilePath || !file_exists($this->statusFilePath)) {
            return;
        }

        $content = @file_get_contents($this->statusFilePath);
        $statusData = $content !== false ? json_decode($content, true) : [];

        if (!\is_array($statusData)) {
            $statusData = ['task_id' => $this->taskId];
        }

        $statusData = array_merge($statusData, [
            'status' => 'CANCELLED',
            'message' => 'Task terminated by parent process',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        @file_put_contents(
            $this->statusFilePath, 
            json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}