<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Interfaces\PersistentPoolExecutorInterface;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Managers\ProcessPool;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * @internal Concrete implementation — use ParallelExecutor::createPersistentPool()
 */
final class PersistentPoolExecutor implements PersistentPoolExecutorInterface
{
    private int $timeoutSeconds = 60;

    private bool $unlimitedTimeout = false;

    private ?string $memoryLimit = null;

    private ?int $maxNestingLevel = null;

    private ?ProcessPool $pool = null;

    private bool $isShutdown = false;

    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(): mixed)|null}|null
     */
    private ?array $bootstrap = null;

    private function __construct(private readonly int $size)
    {
    }

    /** @internal */
    public static function new(int $size): self
    {
        return new self($size);
    }

    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeoutSeconds = $seconds;
        $clone->unlimitedTimeout = false;

        return $clone;
    }

    public function withoutTimeout(): static
    {
        $clone = clone $this;
        $clone->unlimitedTimeout = true;

        return $clone;
    }

    public function withMemoryLimit(string $limit): static
    {
        $clone = clone $this;
        $clone->memoryLimit = $limit;

        return $clone;
    }

    public function withUnlimitedMemory(): static
    {
        return $this->withMemoryLimit('-1');
    }

    public function withBootstrap(string $file, ?callable $callback = null): static
    {
        $clone = clone $this;
        $clone->bootstrap = [
            'name' => 'custom',
            'bootstrap_file' => $file,
            'bootstrap_callback' => $callback,
        ];

        return $clone;
    }

    public function withMaxNestingLevel(int $level): static
    {
        if ($level < 1 || $level > 10) {
            throw new \InvalidArgumentException('max_nesting_level must be between 1 and 10.');
        }

        $clone = clone $this;
        $clone->maxNestingLevel = $level;

        return $clone;
    }

    /**
     * @template TResult
     * @param callable(): TResult $task
     * @return PromiseInterface<TResult>
     */
    public function run(callable $task): PromiseInterface
    {
        if ($this->isShutdown) {
            return Promise::rejected(new \RuntimeException('Cannot submit task to a shutdown pool.'));
        }

        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        return $this->getPool()->submit($task, $finalTimeout);
    }

    public function shutdown(): void
    {
        $this->isShutdown = true;
        $this->pool?->shutdown();
        $this->pool = null;
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    private function getPool(): ProcessPool
    {
        if ($this->pool === null) {
            $manager = ProcessManager::getGlobal();

            $this->pool = new ProcessPool(
                size: $this->size,
                spawnHandler: $manager->getSpawnHandler(),
                serializer: $manager->getSerializer(),
                systemUtils: $manager->getSystemUtils(),
                frameworkInfo: $this->bootstrap ?? $manager->getFrameworkBootstrap(),
                memoryLimit: $this->memoryLimit,
                maxNestingLevel: $this->maxNestingLevel ?? $manager->getMaxNestingLevel(),
            );
        }

        return $this->pool;
    }
}
