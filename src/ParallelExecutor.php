<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Immutable, fluent builder for executing parallel tasks and background processes.
 *
 * This class allows you to override global configurations (like memory limits,
 * timeouts, and logging) on a per-task basis.
 */
final class ParallelExecutor
{
    /**
     *  @var array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null
     */
    private ?array $bootstrap = null;

    private ?string $memoryLimit = null;

    private ?bool $loggingEnabled = null;

    private int $timeoutSeconds = 60;

    private bool $unlimitedTimeout = false;

    private ?int $maxNestingLevel = null;

    public function __construct() {}

    /**
     * Create a new instance of the ParallelExecutor.
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Set the maximum execution time in seconds for this specific task.
     *
     * @param int $seconds
     * @return self
     */
    public function withTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->timeoutSeconds = $seconds;
        $clone->unlimitedTimeout = false;

        return $clone;
    }

    /**
     * Allows the child process to run without any time limit.
     * This is equivalent to `set_time_limit(0)` in the child process.
     *
     * @return self
     */
    public function withoutTimeout(): self
    {
        $clone = clone $this;
        $clone->unlimitedTimeout = true;

        return $clone;
    }

    /**
     * Set the PHP memory limit for this specific task (e.g., '512M', '2G').
     *
     * @param string $limit
     * @return self
     */
    public function withMemoryLimit(string $limit): self
    {
        $clone = clone $this;
        $clone->memoryLimit = $limit;

        return $clone;
    }

    /**
     * Allows the child process to use unlimited memory.
     * This is equivalent to `ini_set('memory_limit', -1)` in the child process.
     *
     * @return self
     */
    public function withUnlimitedMemory(): self
    {
        return $this->withMemoryLimit('-1');
    }

    /**
     * Explicitly enable detailed status logging for this task.
     *
     * @return self
     */
    public function withLogging(): self
    {
        $clone = clone $this;
        $clone->loggingEnabled = true;

        return $clone;
    }

    /**
     * Explicitly disable detailed status logging for this task to save I/O overhead.
     *
     * @return self
     */
    public function withoutLogging(): self
    {
        $clone = clone $this;
        $clone->loggingEnabled = false;

        return $clone;
    }

    /**
     * Set a custom framework bootstrap specifically for this task.
     *
     * @param string $file Absolute path to the bootstrap file
     * @param callable|null $callback Optional callback to execute after requiring the file
     * @return self
     */
    public function withBootstrap(string $file, ?callable $callback = null): self
    {
        $clone = clone $this;
        $clone->bootstrap = [
            'name' => 'custom',
            'bootstrap_file' => $file,
            'bootstrap_callback' => $callback,
        ];

        return $clone;
    }

    /**
     * Set the maximum nesting level for this specific task.
     *
     * @param int $level Maximum nesting depth (1-10)
     * @return self
     */
    public function withMaxNestingLevel(int $level): self
    {
        if ($level < 1 || $level > 10) {
            throw new \InvalidArgumentException('max_nesting_level must be between 1 and 10.');
        }

        $clone = clone $this;
        $clone->maxNestingLevel = $level;

        return $clone;
    }

    /**
     * Executes the given task in a parallel child process and streams the result.
     *
     * @template TResult
     * @param callable(): TResult $task
     * @return PromiseInterface<TResult>
     */
    public function run(callable $task): PromiseInterface
    {
        $source = new CancellationTokenSource();
        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        $process = ProcessManager::getGlobal()->spawnStreamedTask(
            $task,
            $finalTimeout,
            $this->memoryLimit,
            $this->loggingEnabled,
            $this->bootstrap,
            $this->maxNestingLevel
        );

        $source->token->onCancel(static function () use ($process) {
            $process->terminate();
        });

        /** @var PromiseInterface<TResult> */
        return $process->getResult($finalTimeout)
            ->onCancel(static function () use ($source) {
                $source->cancel();
            })
        ;
    }

    /**
     * Spawns a detached fire-and-forget background process.
     *
     * @param callable $task
     * @return PromiseInterface<BackgroundProcess>
     */
    public function spawn(callable $task): PromiseInterface
    {
        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        return Promise::resolved(
            ProcessManager::getGlobal()->spawnBackgroundTask(
                $task,
                $finalTimeout,
                $this->memoryLimit,
                $this->loggingEnabled,
                $this->bootstrap,
                $this->maxNestingLevel
            )
        );
    }

    public function withPersistentPool(int $size): PersistentPoolExecutor
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Pool size must be at least 1.');
        }

        return new PersistentPoolExecutor(
            $size,
            $this->bootstrap ?? ProcessManager::getGlobal()->getFrameworkBootstrap(), 
            $this->memoryLimit,
            $this->maxNestingLevel ?? ProcessManager::getGlobal()->getMaxNestingLevel()
        );
    }
}
