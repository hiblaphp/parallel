<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Defer;
use Hibla\Parallel\Process;

/**
 * Process pool manager for limiting concurrent background tasks
 */
class ProcessPool
{
    private int $maxConcurrentTasks;
    private array $activeTasks = [];
    private array $queuedTasks = [];
    private array $allTaskIds = []; // Track all task IDs that should be returned
    private int $pollIntervalMs;

    public function __construct(int $maxConcurrentTasks = 5, int $pollIntervalMs = 100)
    {
        $this->maxConcurrentTasks = max(1, $maxConcurrentTasks);
        $this->pollIntervalMs = max(10, $pollIntervalMs);
    }

    /**
     * Execute tasks with pool management
     * 
     * @param array $tasks Array of [callback, context] pairs with preserved keys
     * @return array Task IDs with preserved keys
     */
    public function executeTasks(array $tasks): array
    {
        $this->allTaskIds = []; 
        
        foreach ($tasks as $key => $taskData) {
            $this->queuedTasks[] = [
                'key' => $key,
                'callback' => $taskData['callback'],
                'context' => $taskData['context'] ?? []
            ];
        }

        while (!empty($this->queuedTasks) || !empty($this->activeTasks)) {
            $this->processQueue();
            
            $this->checkCompletedTasks();
            
            if (!empty($this->queuedTasks) || !empty($this->activeTasks)) {
                usleep($this->pollIntervalMs * 1000);
            }
        }

        return $this->allTaskIds;
    }

    /**
     * Check for completed tasks and remove them from active pool
     */
    private function checkCompletedTasks(): void
    {
        foreach ($this->activeTasks as $index => $task) {
            $status = Process::getTaskStatus($task['task_id']);

            if (in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                unset($this->activeTasks[$index]);
                $this->activeTasks = array_values($this->activeTasks); 
            }
        }
    }

    /**
     * Process queued tasks up to the pool limit
     */
    private function processQueue(): void
    {
        while (count($this->activeTasks) < $this->maxConcurrentTasks && !empty($this->queuedTasks)) {
            $task = array_shift($this->queuedTasks);

            try {
                $taskId = Process::spawn($task['callback'], $task['context']);
                
                $this->allTaskIds[$task['key']] = $taskId;
                
                $this->activeTasks[] = [
                    'key' => $task['key'],
                    'task_id' => $taskId,
                    'started_at' => time()
                ];
            } catch (\Throwable $e) {
                error_log("Failed to start pooled task {$task['key']}: " . $e->getMessage());
                $fakeTaskId = 'failed_' . $task['key'] . '_' . time();
                $this->allTaskIds[$task['key']] = $fakeTaskId;
            }
        }
    }

    /**
     * Get current pool statistics
     */
    public function getStats(): array
    {
        return [
            'max_concurrent' => $this->maxConcurrentTasks,
            'active_tasks' => count($this->activeTasks),
            'queued_tasks' => count($this->queuedTasks),
            'completed_task_ids' => count($this->allTaskIds),
            'poll_interval_ms' => $this->pollIntervalMs
        ];
    }

    /**
     * Wait for all active tasks to complete (used internally)
     */
    public function waitForCompletion(int $timeoutSeconds = 0): bool
    {
        $startTime = time();

        while (!empty($this->queuedTasks) || !empty($this->activeTasks)) {
            $this->processQueue();
            $this->checkCompletedTasks();

            if ($timeoutSeconds > 0 && (time() - $startTime) >= $timeoutSeconds) {
                error_log("Pool completion timeout. Queued: " . count($this->queuedTasks) . ", Active: " . count($this->activeTasks));
                return false;
            }

            usleep($this->pollIntervalMs * 1000);
        }

        return true;
    }
}