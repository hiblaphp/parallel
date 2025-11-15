<?php

namespace Hibla\Parallel\Handlers;

use Hibla\Parallel\Serialization\CallbackSerializationManager;

class BackgroundTaskManager
{
    /**
     * @var BackgroundProcessExecutorHandler Background process executor
     */
    private BackgroundProcessExecutorHandler $backgroundExecutor;

    /**
     * @var CallbackSerializationManager Serialization manager
     */
    private CallbackSerializationManager $serializationManager;

    public function __construct(?CallbackSerializationManager $serializationManager = null)
    {
        $this->serializationManager = $serializationManager ?? new CallbackSerializationManager();
        $this->backgroundExecutor = new BackgroundProcessExecutorHandler($this->serializationManager);
    }

    /**
     * Execute callback in background and return task ID
     *
     * @param callable $callback The callback to execute
     * @param array $context Additional context for the callback
     * @return string Task ID
     * @throws \Throwable If background execution fails
     */
    public function execute(callable $callback, array $context = []): string
    {
        try {
            return $this->backgroundExecutor->execute($callback, $context);
        } catch (\Throwable $e) {
            error_log('Background execution failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get task status by ID
     */
    public function getTaskStatus(string $taskId): array
    {
        return $this->backgroundExecutor->getTaskStatus($taskId);
    }

    /**
     * Get all tasks status
     */
    public function getAllTasksStatus(): array
    {
        return $this->backgroundExecutor->getAllTasksStatus();
    }

    /**
     * Get tasks summary
     */
    public function getTasksSummary(): array
    {
        return $this->backgroundExecutor->getTasksSummary();
    }

    /**
     * Get recent logs
     */
    public function getRecentLogs(int $limit = 100): array
    {
        return $this->backgroundExecutor->getRecentLogs($limit);
    }

    /**
     * Clean up old tasks
     */
    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->backgroundExecutor->cleanupOldTasks($maxAgeHours);
    }

    /**
     * Get background executor instance
     */
    public function getBackgroundExecutor(): BackgroundProcessExecutorHandler
    {
        return $this->backgroundExecutor;
    }

    /**
     * Get log directory
     */
    public function getLogDirectory(): string
    {
        return $this->backgroundExecutor->getLogDirectory();
    }

    /**
     * Get background execution statistics
     */
    public function getStats(): array
    {
        return $this->backgroundExecutor->getStats();
    }

    /**
     * Test background execution capabilities
     */
    public function testCapabilities(bool $verbose = false): array
    {
        return $this->backgroundExecutor->testCapabilities($verbose);
    }
}