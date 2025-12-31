<?php

namespace Hibla\Parallel\Utilities;

/**
 * Manages task registration and tracking
 */
class TaskRegistry
{
    /**
     * @var array<string, array{created_at: int, callback_type: string, context_size: int}>
     */
    private array $taskRegistry = [];

    /**
     * Register a new task
     *
     * @param string $taskId Unique identifier for the task
     * @param callable $callback The callback to execute
     * @param array<string, mixed> $context Context data for the task
     */
    public function registerTask(string $taskId, callable $callback): void
    {
        $this->taskRegistry[$taskId] = [
            'created_at' => time(),
            'callback_type' => $this->getCallableType($callback),
        ];
    }

    /**
     * @param callable $callback The callable to inspect
     * @return string Type description (function|method|closure|callable_object)
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