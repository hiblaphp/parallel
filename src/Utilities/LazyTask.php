<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Defer;
use Hibla\Parallel\Process;

class LazyTask
{
    private static array $lazyTasks = [];
    private static int $nextId = 1;

    private string $lazyId;
    private $callback;
    private array $context;
    private ?string $realTaskId = null;
    private bool $executed = false;

    private function __construct(string $lazyId, callable $callback, array $context = [])
    {
        $this->lazyId = $lazyId;
        $this->callback = $callback;
        $this->context = $context;
    }

    /**
     * Create a new lazy task and return its ID
     */
    public static function create(callable $callback, array $context = []): string
    {
        $lazyId = 'lazy_' . self::$nextId++;
        $task = new self($lazyId, $callback, $context);
        self::$lazyTasks[$lazyId] = $task;
        return $lazyId;
    }

    /**
     * Get lazy task by ID
     */
    public static function get(string $lazyId): ?self
    {
        return self::$lazyTasks[$lazyId] ?? null;
    }

    /**
     * Check if a task ID is a lazy task
     */
    public static function isLazyId(string $taskId): bool
    {
        return str_starts_with($taskId, 'lazy_');
    }

    /**
     * Execute the lazy task and return real task ID
     */
    public function execute(): string
    {
        if (!$this->executed) {
            $this->realTaskId = Process::spawn($this->callback, $this->context);
            $this->executed = true;
        }
        
        return $this->realTaskId;
    }

    /**
     * Get the callback (public method to avoid reflection)
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Get the context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Update context before execution
     */
    public function setContext(array $context): void
    {
        if ($this->executed) {
            throw new \RuntimeException('Cannot modify context after task execution');
        }
        
        $this->context = $context;
    }

    /**
     * Get real task ID (executes if needed)
     */
    public function getRealTaskId(): string
    {
        return $this->execute();
    }

    /**
     * Check if executed
     */
    public function isExecuted(): bool
    {
        return $this->executed;
    }

    /**
     * Clean up lazy tasks (for memory management)
     */
    public static function cleanup(): void
    {
        self::$lazyTasks = [];
        self::$nextId = 1;
    }
}