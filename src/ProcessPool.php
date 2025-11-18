<?php

namespace Hibla\Parallel;

use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

/**
 * Process pool manager for limiting concurrent background tasks
 * Integrated with Hibla's async ecosystem
 */
class ProcessPool
{
    private int $maxConcurrentTasks;
    private array $activeTasks = [];
    private array $queuedTasks = [];
    private array $allTaskIds = [];
    private int $pollIntervalMs;

    public function __construct(int $maxConcurrentTasks = 5, int $pollIntervalMs = 100)
    {
        $this->maxConcurrentTasks = max(1, $maxConcurrentTasks);
        $this->pollIntervalMs = max(10, $pollIntervalMs);
    }

    /**
     * Execute tasks with pool management (async version)
     *
     * @param array $tasks Array of [callback, context] pairs with preserved keys
     * @return PromiseInterface<array> Promise resolving to task IDs with preserved keys
     */
    public function executeTasksAsync(array $tasks): PromiseInterface
    {
        return async(function () use ($tasks) {
            $this->allTaskIds = [];

            foreach ($tasks as $key => $taskData) {
                $this->queuedTasks[] = [
                    'key' => $key,
                    'callback' => $taskData['callback'],
                    'context' => $taskData['context'] ?? []
                ];
            }

            // Process initial batch
            await($this->processQueueAsync());

            return $this->allTaskIds;
        });
    }

    /**
     * Wait for all active tasks to complete (async version)
     */
    public function waitForCompletionAsync(int $timeoutSeconds = 0): PromiseInterface
    {
        return async(function () use ($timeoutSeconds) {
            $startTime = time();

            while (!empty($this->queuedTasks) || !empty($this->activeTasks)) {
                await($this->processQueueAsync());
                await($this->checkCompletedTasksAsync());

                if ($timeoutSeconds > 0 && time() - $startTime >= $timeoutSeconds) {
                    error_log("Pool completion timeout. Queued: " . \count($this->queuedTasks) . ", Active: " . count($this->activeTasks));
                    return false;
                }

                if (!empty($this->queuedTasks) || !empty($this->activeTasks)) {
                    await(delay($this->pollIntervalMs / 1000)); // Convert ms to seconds
                }
            }

            return true;
        });
    }

    /**
     * Check for completed tasks and remove them from active pool (async)
     */
    private function checkCompletedTasksAsync(): PromiseInterface
    {
        return async(function () {
            foreach ($this->activeTasks as $index => $task) {
                $status = Process::getTaskStatus($task['task_id']);

                if (\in_array($status['status'], ['COMPLETED', 'ERROR', 'NOT_FOUND'])) {
                    unset($this->activeTasks[$index]);
                    $this->activeTasks = array_values($this->activeTasks);
                }
            }
        });
    }

    /**
     * Process queued tasks up to the pool limit (async)
     */
    private function processQueueAsync(): PromiseInterface
    {
        return async(function () {
            while (\count($this->activeTasks) < $this->maxConcurrentTasks && !empty($this->queuedTasks)) {
                $task = array_shift($this->queuedTasks);

                try {
                    $taskId = await(Process::spawn($task['callback'], $task['context']));

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
        });
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
}
