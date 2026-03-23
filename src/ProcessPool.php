<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Managers\ProcessPoolManager;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Rcalicdan\ConfigLoader\Config;

use function Hibla\await;

/**
 * Class for managing a pool of persistent worker processes.
 */
final class ProcessPool implements ProcessPoolInterface
{
    private ?int $timeoutSeconds = null;

    private bool $unlimitedTimeout = false;

    private ?string $memoryLimit = null;

    private ?int $maxNestingLevel = null;

    private ?ProcessPoolManager $pool = null;

    private bool $isShutdown = false;

    private bool $spawnEagerly = true;

    /**
     * Registered pool-level message handlers in registration order.
     * All fire before the per-task handler passed to run().
     *
     * @var array<int, callable(WorkerMessage): void>
     */
    private array $onMessageHandlers = [];

    /**
     * @var array{name: string, bootstrap_file: string|null, bootstrap_callback: (callable(string): mixed)|null}|null
     */
    private ?array $bootstrap = null;

    /**
     * Maximum number of tasks a single worker executes before retiring and
     * being replaced by a fresh worker. Null means unlimited.
     *
     * @var int<1, max>|null
     */
    private ?int $maxExecutionsPerWorker = null;

    /**
     * @var callable(ProcessPoolInterface): void|null
     */
    private $onRespawnHandler = null;

    public function __construct(private readonly int $size) {}

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
    public function withLazySpawning(): static
    {
        $clone = clone $this;
        $clone->spawnEagerly = false;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function onMessage(callable $handler): static
    {
        $clone = clone $this;
        $clone->onMessageHandlers[] = $handler;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function getWorkerCount(): int
    {
        if ($this->pool === null) {
            return 0;
        }

        return $this->pool->getWorkerCount();
    }

    /**
     * @inheritdoc
     */
    public function getWorkerPids(): array
    {
        if ($this->pool === null) {
            return [];
        }

        return $this->pool->getWorkerPids();
    }

    /**
     * @inheritdoc
     */
    public function withMaxExecutionsPerWorker(int $maxExecutions): static
    {
        if ($maxExecutions < 1) {
            throw new \InvalidArgumentException(
                'Max executions per worker must be at least 1. Got: ' . $maxExecutions
            );
        }

        $clone = clone $this;
        /** @var int<1, max> $maxExecutions */
        $clone->maxExecutionsPerWorker = $maxExecutions;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function onWorkerRespawn(callable $handler): static
    {
        $clone = clone $this;
        $clone->onRespawnHandler = $handler;

        return $clone;
    }

    /**
     * @inheritdoc
     */
    public function boot(): static
    {
        $pool = $this->getPool();

        await($pool->waitUntilReady());

        return $this;
    }

    /**
     * @template TResult
     * @inheritdoc
     * @return PromiseInterface<TResult>
     */
    public function run(callable $callback, ?callable $onMessage = null): PromiseInterface
    {
        if ($this->isShutdown) {
            return Promise::rejected(new \RuntimeException('Cannot submit task to a shutdown pool.'));
        }

        $sourceLocation = 'unknown';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if (
                $file !== ''
                && ! str_contains($file, 'ProcessPool.php')
                && ! str_contains($file, 'Parallel.php')
            ) {
                $sourceLocation = $file . ':' . ($frame['line'] ?? '?');

                break;
            }
        }

        $configTimeout = Config::loadFromRoot('hibla_parallel', 'process.timeout', 60);
        assert(\is_int($configTimeout));
        $timeout = $this->timeoutSeconds ?? $configTimeout;
        $finalTimeout = $this->unlimitedTimeout ? 0 : $timeout;

        /** @var PromiseInterface<TResult> */
        return $this->getPool()->submit($callback, $finalTimeout, $sourceLocation, $onMessage);
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
     * @inheritdoc
     * @return PromiseInterface<void>
     */
    public function shutdownAsync(): PromiseInterface
    {
        $this->isShutdown = true;

        if ($this->pool !== null) {
            $promise = $this->pool->shutdownAsync();
            $promise->finally(function () {
                $this->pool = null;
                $this->onMessageHandlers = [];
                $this->onRespawnHandler = null;
            });

            return $promise;
        }

        return Promise::resolved();
    }

    /**
     * @inheritdoc
     */
    public function shutdown(): void
    {
        $this->isShutdown = true;
        $this->pool?->shutdown();
        $this->pool = null;

        $this->onMessageHandlers = [];
        $this->onRespawnHandler = null;
    }

    private function getPool(): ProcessPoolManager
    {
        if ($this->pool === null) {
            $manager = ProcessManager::getGlobal();

            // Wrap the handler to inject the pool instance safely without memory leaks
            $internalRespawnHandler = null;
            if ($this->onRespawnHandler !== null) {
                $weakThis = \WeakReference::create($this);
                $userHandler = $this->onRespawnHandler;

                $internalRespawnHandler = static function () use ($weakThis, $userHandler): void {
                    $pool = $weakThis->get();
                    if ($pool !== null) {
                        $userHandler($pool);
                    }
                };
            }

            $this->pool = new ProcessPoolManager(
                size: $this->size,
                spawnHandler: $manager->getSpawnHandler(),
                serializer: $manager->getSerializer(),
                frameworkInfo: $this->bootstrap ?? $manager->getFrameworkBootstrap(),
                memoryLimit: $this->memoryLimit,
                maxNestingLevel: $this->maxNestingLevel ?? $manager->getMaxNestingLevel(),
                onMessageHandlers: $this->onMessageHandlers,
                spawnEagerly: $this->spawnEagerly,
                maxExecutionsPerWorker: $this->maxExecutionsPerWorker,
                onWorkerRespawn: $internalRespawnHandler,
                timeoutSeconds: $this->timeoutSeconds,
            );
        }

        return $this->pool;
    }

    /**
     * Automatically shut down the pool, release resources, and clear handler
     * references when garbage collected.
     */
    public function __destruct()
    {
        if (! $this->isShutdown) {
            $this->shutdown();
        }

        $this->onMessageHandlers = [];
        $this->onRespawnHandler = null;
    }
}
