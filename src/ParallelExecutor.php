<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Cancellation\CancellationTokenSource;
use Hibla\Parallel\Interfaces\ParallelExecutorInterface;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Traits\MessageHandlerComposer;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;
use Rcalicdan\ConfigLoader\Config;

/**
 * Class for executing one-off parallel tasks and background processes.
 */
final class ParallelExecutor implements ParallelExecutorInterface
{
    use MessageHandlerComposer;

    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}|null
     */
    private ?array $bootstrap = null;

    private ?string $memoryLimit = null;

    private ?int $maxNestingLevel = null;

    private ?int $timeoutSeconds = null;

    private bool $unlimitedTimeout = false;

    /**
     * Registered executor-level message handlers in registration order.
     * All fire before the per-task handler passed to run().
     *
     * @var array<int, callable(WorkerMessage): void>
     */
    private array $onMessageHandlers = [];

    public function __construct() {}

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
    public function onMessage(callable $handler): static
    {
        $clone = clone $this;
        // Append to preserve registration order — handlers fire in the order
        // they are registered, consistent with the middleware convention.
        $clone->onMessageHandlers[] = $handler;

        return $clone;
    }

    /**
     * @template TResult
     * @inheritdoc
     * @return PromiseInterface<TResult>
     */
    public function run(callable $callback, ?callable $onMessage = null): PromiseInterface
    {
        $source = new CancellationTokenSource();

        $configTimeout = Config::loadFromRoot('hibla_parallel', 'background_process.timeout', 600);
        assert(\is_int($configTimeout));
        $timeout = $this->timeoutSeconds ?? $configTimeout;
        $finalTimeout = $this->unlimitedTimeout ? 0 : $timeout;

        $process = ProcessManager::getGlobal()->spawnStreamedTask(
            $callback,
            $finalTimeout,
            $this->memoryLimit,
            $this->bootstrap,
            $this->maxNestingLevel
        );

        $source->token->onCancel(static function () use ($process) {
            $process->terminate();
        });

        // Compose all executor-level handlers (in registration order) followed
        // by the per-task handler into a single callable. Executor-level handlers
        // act as the outer middleware layer and always fire first.
        $composedHandler = $this->composeMessageHandlers($this->onMessageHandlers, $onMessage);

        /** @var PromiseInterface<TResult> */
        return $process->getResult($finalTimeout, $composedHandler)
            ->onCancel(static function () use ($source) {
                $source->cancel();
            })
        ;
    }

    /**
     * @template TResult
     * @inheritdoc
     * @param callable(mixed ...$args): TResult $task
     * @return callable(mixed ...$args): PromiseInterface<TResult>
     */
    public function runFn(callable $task, ?callable $onMessage = null): callable
    {
        return function (mixed ...$args) use ($task, $onMessage): PromiseInterface {
            return $this->run(static fn() => $task(...$args), $onMessage);
        };
    }

    /**
     * Explicitly clear handler and bootstrap references when the executor is
     * garbage collected to prevent closures from holding external object
     * references longer than necessary.
     */
    public function __destruct()
    {
        $this->onMessageHandlers = [];
        $this->bootstrap = null;
    }
}
