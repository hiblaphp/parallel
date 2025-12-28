<?php

namespace Hibla\Parallel\Handlers;

/**
 * Handles task status persistence and file management.
 * 
 * This class is strictly responsible for reading and writing task metadata 
 * to the storage (JSON files). It does not control process execution.
 */
final readonly class TaskStatusHandler
{
    /**
     * @param string $logDir Directory path for storing task status files
     */
    public function __construct(private string $logDir) {}

    /**
     * Creates initial status file for a new task.
     *
     * Generates a JSON status file containing initial task metadata including
     * callback type, context size, and timestamps.
     *
     * @param string $taskId Unique identifier for the task
     * @param callable $callback The callback function to be executed
     * @param array<string, mixed> $context Contextual data for the task
     * @return void
     */
    public function createInitialStatus(string $taskId, callable $callback, array $context): void
    {
        $statusFile = $this->logDir . DIRECTORY_SEPARATOR . $taskId . '.json';

        /** @var array<string, mixed> $initialStatus */
        $initialStatus = [
            'task_id' => $taskId,
            'status' => 'PENDING',
            'message' => 'Task created and queued for execution',
            'timestamp' => time(),
            'duration' => null,
            'memory_usage' => null,
            'memory_peak' => null,
            'pid' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'callback_type' => $this->getCallableType($callback),
            'context_size' => \count($context)
        ];

        file_put_contents($statusFile, json_encode($initialStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }


    /**
     * Gets callable type string for logging metadata.
     *
     * Determines the type of callable (function, method, closure, or callable object)
     * for metadata and debugging purposes.
     *
     * @param callable $callback The callback to analyze
     * @return string Type identifier ('function', 'method', 'closure', or 'callable_object')
     */
    private function getCallableType(callable $callback): string
    {
        if (\is_string($callback)) {
            return 'function';
        } elseif (\is_array($callback)) {
            return 'method';
        } elseif ($callback instanceof \Closure) {
            return 'closure';
        } else {
            return 'callable_object';
        }
    }
}
