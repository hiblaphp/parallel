<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Interfaces\NonPersistentExecutorInterface;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * @internal Concrete implementation — use ParallelExecutor::create()
 */
final class NonPersistentExecutor implements NonPersistentExecutorInterface
{
    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null
     */
    private ?array $bootstrap = null;

    private ?string $memoryLimit = null;

    private ?int $maxNestingLevel = null;

    private int $timeoutSeconds = 60;

    private bool $unlimitedTimeout = false;

    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
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
        $source = new CancellationTokenSource();
        $finalTimeout = $this->unlimitedTimeout ? 0 : $this->timeoutSeconds;

        $process = ProcessManager::getGlobal()->spawnStreamedTask(
            $task,
            $finalTimeout,
            $this->memoryLimit,
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
                $this->bootstrap,
                $this->maxNestingLevel
            )
        );
    }
}
