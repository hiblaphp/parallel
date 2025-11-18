<?php

namespace Hibla\Parallel\Utilities;

/**
 * Manages task registration and tracking
 */
class TaskRegistry
{
    private array $taskRegistry = [];

    /**
     * Register a new task
     */
    public function registerTask(string $taskId, callable $callback, array $context): void
    {
        $this->taskRegistry[$taskId] = [
            'created_at' => time(),
            'callback_type' => $this->getCallableType($callback),
            'context_size' => \count($context)
        ];
    }

    /**
     * Get all registered tasks
     */
    public function getAllTasks(): array
    {
        return $this->taskRegistry;
    }

    /**
     * Get task count
     */
    public function getTaskCount(): int
    {
        return count($this->taskRegistry);
    }

    /**
     * Clear completed tasks from registry
     */
    public function clearCompletedTasks(int $maxAge, $statusManager): int
    {
        $cutoffTime = time() - $maxAge;
        $cleared = 0;

        foreach ($this->taskRegistry as $taskId => $info) {
            if ($info['created_at'] < $cutoffTime) {
                $status = $statusManager->getTaskStatus($taskId);
                if (\in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                    unset($this->taskRegistry[$taskId]);
                    $cleared++;
                }
            }
        }

        return $cleared;
    }

    /**
     * Get callable type for logging
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