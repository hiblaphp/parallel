<?php

declare(strict_types=1);

namespace Hibla\Parallel\Managers;

use Hibla\Parallel\Exceptions\NestingLimitException;
use Hibla\Parallel\Exceptions\RateLimitException;
use Hibla\Parallel\Exceptions\TaskPayloadException;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Parallel\Internals\Process;
use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\ConfigLoader\Config;
use Rcalicdan\Serializer\CallbackSerializationManager;

/**
 * @internal
 *
 * Manages the lifecycle of parallel processes and background tasks.
 *
 * This class serves as the central coordinator for spawning, tracking, and managing
 * parallel tasks. It handles both streamed tasks (with real-time output) and
 * fire-and-forget background tasks. Implements a singleton pattern for global access,
 * but allows direct instantiation for testing and dependency injection.
 */
class ProcessManager
{
    /**
     * Default maximum number of background tasks allowed to spawn per second.
     */
    public const int DEFAULT_SPAWN_LIMIT = 50;

    /**
     * Default maximum nesting level for parallel processes.
     */
    public const int DEFAULT_MAX_NESTING_LEVEL = 5;

    /**
     * @var self|null Singleton instance of the ProcessManager
     */
    private static ?self $instance = null;

    private ProcessSpawnHandler $spawnHandler;

    private SystemUtilities $systemUtils;

    private CallbackSerializationManager $serializer;

    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}
     */
    private array $frameworkInfo;

    private int $spawnCount = 0;

    private float $lastSpawnReset = 0.0;

    private int $maxSpawnsPerSecond;

    private int $maxNestingLevel;

    /**
     * Gets or creates the global singleton instance.
     *
     * Provides a single global instance of ProcessManager for consistent
     * task management across the application.
     *
     * @return self The singleton ProcessManager instance
     */
    public static function getGlobal(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset the global instance (useful for testing).
     *
     * @param self|null $instance
     */
    public static function setGlobal(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * Allow public instantiation for dependency injection or testing.
     *
     * @param SystemUtilities|null $systemUtils Optional dependency injection for system utilities
     * @param CallbackSerializationManager|null $serializer Optional dependency injection for serialization
     * @param int|null $maxSpawnsPerSecond Optional safety limit for background spawns/sec. If null, loads from config or uses default.
     * @param int|null $maxNestingLevel Optional maximum nesting level for parallel processes. If null, loads from config or uses default.
     */
    public function __construct(
        ?SystemUtilities $systemUtils = null,
        ?CallbackSerializationManager $serializer = null,
        ?int $maxSpawnsPerSecond = null,
        ?int $maxNestingLevel = null
    ) {
        $this->systemUtils = $systemUtils ?? new SystemUtilities();
        $this->serializer = $serializer ?? new CallbackSerializationManager();

        if ($maxSpawnsPerSecond !== null) {
            $this->maxSpawnsPerSecond = $maxSpawnsPerSecond;
        } else {
            $configLimit = Config::loadFromRoot(
                'hibla_parallel',
                'background_process.spawn_limit_per_second',
                self::DEFAULT_SPAWN_LIMIT
            );
            $this->maxSpawnsPerSecond = is_numeric($configLimit) ? (int) $configLimit : self::DEFAULT_SPAWN_LIMIT;
        }

        if ($maxNestingLevel !== null) {
            $this->maxNestingLevel = $maxNestingLevel;
        } else {
            // Prioritize the environment variable if it exists (for nested processes)
            $envNestingLevel = $_SERVER['HIBLA_MAX_NESTING_LEVEL'] ?? $_ENV['HIBLA_MAX_NESTING_LEVEL'] ?? getenv('HIBLA_MAX_NESTING_LEVEL');

            if ($envNestingLevel !== false && $envNestingLevel !== '') {
                $this->maxNestingLevel = is_numeric($envNestingLevel) ? (int) $envNestingLevel : self::DEFAULT_MAX_NESTING_LEVEL;
            } else {
                // Fall back to config file if the env var isn't set (for the main process)
                $configNesting = Config::loadFromRoot(
                    'hibla_parallel',
                    'max_nesting_level',
                    self::DEFAULT_MAX_NESTING_LEVEL
                );
                $this->maxNestingLevel = is_numeric($configNesting) ? (int) $configNesting : self::DEFAULT_MAX_NESTING_LEVEL;
            }
        }

        if ($this->maxNestingLevel < 1) {
            throw new \InvalidArgumentException(
                'max_nesting_level must be at least 1. Got: ' . $this->maxNestingLevel
            );
        }

        if ($this->maxNestingLevel > 10) {
            throw new \InvalidArgumentException(
                'max_nesting_level cannot exceed 10 for safety reasons. Got: ' . $this->maxNestingLevel
            );
        }

        // Set environment variable for child processes to know the maximum allowed level
        putenv("HIBLA_MAX_NESTING_LEVEL={$this->maxNestingLevel}");
        $_ENV['HIBLA_MAX_NESTING_LEVEL'] = (string) $this->maxNestingLevel;
        $_SERVER['HIBLA_MAX_NESTING_LEVEL'] = (string) $this->maxNestingLevel;

        $this->spawnHandler = new ProcessSpawnHandler($this->systemUtils);
        $this->frameworkInfo = $this->systemUtils->getFrameworkBootstrap();
    }

    /**
     * Spawns a streamed task with real-time output communication.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback The callback function to execute in the worker process
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @param string|null $memoryLimit Optional memory limit override
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null $customBootstrap Optional bootstrap override
     * @param int|null $maxNestingLevel Optional max nesting level override
     * @return Process<TResult> The spawned process instance with communication streams
     */
    public function spawnStreamedTask(
        callable $callback,
        int $timeoutSeconds = 60,
        ?string $memoryLimit = null,
        ?array $customBootstrap = null,
        ?int $maxNestingLevel = null
    ): Process {
        $finalMaxNestingLevel = $maxNestingLevel ?? $this->maxNestingLevel;
        $this->validate($callback, $finalMaxNestingLevel);

        $sourceLocation = 'unknown';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (
                $file !== ''
                && ! str_contains($file, 'ProcessManager.php')
                && ! str_contains($file, 'functions.php')
                && ! str_contains($file, 'Parallel.php')
                && ! str_contains($file, 'ParallelExecutor.php')
                && ! str_contains($file, 'ProcessPool.php')
            ) {
                $sourceLocation = $file . ':' . ($frame['line'] ?? '?');

                break;
            }
        }

        $frameworkInfo = $customBootstrap ?? $this->frameworkInfo;

        $process = $this->spawnHandler->spawnStreamedTask(
            $callback,
            $frameworkInfo,
            $this->serializer,
            $timeoutSeconds,
            $sourceLocation,
            $memoryLimit,
            $finalMaxNestingLevel
        );

        /** @var Process<TResult> */
        return $process;
    }

    /**
     * Spawns a fire-and-forget background task.
     *
     * Creates a detached background process that runs independently without
     * maintaining communication channels. Suitable for tasks that don't require
     * real-time output monitoring.
     *
     * @param callable $callback The callback function to execute in the worker process
     * @param int $timeoutSeconds Maximum execution time in seconds
     * @param string|null $memoryLimit Optional memory limit override
     * @param array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null $customBootstrap Optional bootstrap override
     * @param int|null $maxNestingLevel Optional max nesting level override
     * @return BackgroundProcess The spawned background process instance
     * @throws NestingLimitException If task nesting limit is exceeded
     * @throws TaskPayloadException If the callback cannot be serialized
     * @throws RateLimitException If rate limit is exceeded
     */
    public function spawnBackgroundTask(
        callable $callback,
        int $timeoutSeconds = 600,
        ?string $memoryLimit = null,
        ?array $customBootstrap = null,
        ?int $maxNestingLevel = null
    ): BackgroundProcess {
        if (microtime(true) - $this->lastSpawnReset > 1.0) {
            $this->spawnCount = 0;
            $this->lastSpawnReset = microtime(true);
        }

        if ($this->spawnCount >= $this->maxSpawnsPerSecond) {
            throw new RateLimitException(
                message: "Safety Limit: Cannot spawn more than {$this->maxSpawnsPerSecond} background tasks per second."
            );
        }

        $this->spawnCount++;

        $finalMaxNestingLevel = $maxNestingLevel ?? $this->maxNestingLevel;
        $this->validate($callback, $finalMaxNestingLevel);

        $frameworkInfo = $customBootstrap ?? $this->frameworkInfo;

        return $this->spawnHandler->spawnBackgroundTask(
            $callback,
            $frameworkInfo,
            $this->serializer,
            $timeoutSeconds,
            $memoryLimit,
            $finalMaxNestingLevel
        );
    }

    /**
     * Get the current nesting level.
     *
     * @return int Current nesting level (0 = main process, 1+ = nested)
     */
    public function getCurrentNestingLevel(): int
    {
        $nestingLevel = $_SERVER['DEFER_NESTING_LEVEL'] ?? $_ENV['DEFER_NESTING_LEVEL'] ?? getenv('DEFER_NESTING_LEVEL');

        return is_numeric($nestingLevel) ? (int) $nestingLevel : 0;
    }

    /**
     * Get the maximum allowed nesting level.
     *
     * @return int Maximum nesting level
     */
    public function getMaxNestingLevel(): int
    {
        return $this->maxNestingLevel;
    }

    /**
     * Get the instance of spawn handler.
     */
    public function getSpawnHandler(): ProcessSpawnHandler
    {
        return $this->spawnHandler;
    }

    /**
     * Get the callback payload serializer.
     */
    public function getSerializer(): CallbackSerializationManager
    {
        return $this->serializer;
    }

    /**
     * Get the system utilities instance.
     */
    public function getSystemUtils(): SystemUtilities
    {
        return $this->systemUtils;
    }

    /**
     * Get the framework bootstrap configuration.
     *
     * @return array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}
     */
    public function getFrameworkBootstrap(): array
    {
        return $this->frameworkInfo;
    }

    /**
     * Validates the callback and nesting level before spawning.
     *
     * @param callable $callback The callback to validate
     * @param int $maxNestingLevel The explicit max nesting level for this task
     * @return void
     * @throws TaskPayloadException If the callback cannot be serialized
     */
    private function validate(callable $callback, int $maxNestingLevel): void
    {
        $currentLevel = $this->getCurrentNestingLevel();

        if (!$this->serializer->canSerializeCallback($callback)) {
            throw new TaskPayloadException(
                'Callback cannot be serialized. ' .
                'Please ensure it is a valid PHP callable and does not contain unserializable types.'
            );
        }

        if ($currentLevel >= $maxNestingLevel) {
            throw new NestingLimitException(
                'Cannot spawn parallel task: Already at maximum nesting level ' .
                    "{$currentLevel}/{$maxNestingLevel}. " .
                    "To increase this limit, configure 'max_nesting_level' in your hibla_parallel config file, " .
                    'or use ->withMaxNestingLevel() on the Parallel. ' .
                    'Maximum safe limit is 10 levels.'
            );
        }
    }
}
