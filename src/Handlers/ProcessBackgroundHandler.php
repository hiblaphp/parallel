<?php 

namespace Hibla\Parallel\Handlers;

class ProcessBackgroundHandler
{
    /**
     * @var BackgroundTaskManager Background task manager
     */
    private BackgroundTaskManager $backgroundTaskManager;

    public function __construct()
    {
        $this->backgroundTaskManager = new BackgroundTaskManager();
    }

    /**
     * Execute callback in background and return task ID
     */
    public function executeBackground(callable $callback, array $context = []): string
    {
        return $this->backgroundTaskManager->execute($callback, $context);
    }

    public function getTaskStatus(string $taskId): array
    {
        return $this->backgroundTaskManager->getTaskStatus($taskId);
    }

    public function getAllTasksStatus(): array
    {
        return $this->backgroundTaskManager->getAllTasksStatus();
    }

    public function getTasksSummary(): array
    {
        return $this->backgroundTaskManager->getTasksSummary();
    }

    public function getRecentLogs(int $limit = 100): array
    {
        return $this->backgroundTaskManager->getRecentLogs($limit);
    }

    public function cleanupOldTasks(int $maxAgeHours = 24): int
    {
        return $this->backgroundTaskManager->cleanupOldTasks($maxAgeHours);
    }

    public function getBackgroundExecutor(): BackgroundProcessExecutorHandler
    {
        return $this->backgroundTaskManager->getBackgroundExecutor();
    }

    public function getLogDirectory(): string
    {
        return $this->backgroundTaskManager->getLogDirectory();
    }

    /**
     * Test background execution capabilities
     */
    public function testBackgroundExecution(bool $verbose = false): array
    {
        return $this->backgroundTaskManager->testCapabilities($verbose);
    }
}