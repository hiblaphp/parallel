<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Interfaces\BackgroundExecutorInterface;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Class for spawning fire-and-forget background processes.
 */
final class BackgroundExecutor implements BackgroundExecutorInterface
{
    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null
     */
    private ?array $bootstrap = null;

    private ?string $memoryLimit = null;

    private ?int $maxNestingLevel = null;

    private int $timeoutSeconds = 600;

    private bool $unlimitedTimeout = false;

    public function __construct()
    {
    }

    /**
     * @inheritdoc
     */
    public function withTimeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeoutSeconds = $seconds;
        $clone->unlimitedTimeout = false;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function withoutTimeout(): static
    {
        $clone = clone $this;
        $clone->unlimitedTimeout = true;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function withMemoryLimit(string $limit): static
    {
        $clone = clone $this;
        $clone->memoryLimit = $limit;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function withUnlimitedMemory(): static
    {
        return $this->withMemoryLimit('-1');
    }

    /**
     * @inheritdoc
     */
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

    /**
     * @inheritdoc
     */
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
     * @inheritdoc
     */
    public function spawn(callable $callback): PromiseInterface
    {
        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        return Promise::resolved(
            ProcessManager::getGlobal()->spawnBackgroundTask(
                $callback,
                $finalTimeout,
                $this->memoryLimit,
                $this->bootstrap,
                $this->maxNestingLevel
            )
        );
    }
}
