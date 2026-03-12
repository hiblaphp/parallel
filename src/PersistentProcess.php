<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use function Hibla\async;
use function Hibla\await;

use Hibla\Parallel\Handlers\ExceptionHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

/**
 * @internal Represents a single, long-running persistent worker process.
 */
final class PersistentProcess
{
    /**
     * @var array<string, Promise<mixed>>
     */
    private array $pendingTasks = [];

    /**
     *  @var callable(self): void
     */
    private $onReadyCallback;

    /**
     *  @var callable(self): void
     */
    private $onCrashCallback;

    private bool $isAlive = true;

    private bool $isBusy = true;

    public function __construct(
        private readonly int $pid,
        private readonly mixed $processResource,
        private readonly PromiseWritableStreamInterface $stdin,
        private readonly PromiseReadableStreamInterface $stdout,
        private readonly PromiseReadableStreamInterface $stderr
    ) {
    }

    /**
     * @param callable(self): void $onReadyCallback
     * @param callable(self): void $onCrashCallback
     */
    public function startReadLoop(callable $onReadyCallback, callable $onCrashCallback): void
    {
        $this->onReadyCallback = $onReadyCallback;
        $this->onCrashCallback = $onCrashCallback;

        async(function () {
            try {
                while (null !== ($line = await($this->stdout->readLineAsync()))) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $data = @json_decode($line, true);
                    if (! \is_array($data)) {
                        continue;
                    }

                    /** @var array<string, mixed> $data */
                    $status = isset($data['status']) && is_string($data['status'])
                        ? $data['status']
                        : '';

                    if ($status === 'CRASHED') {
                        $this->terminate();
                        ($this->onCrashCallback)($this);

                        break;
                    }

                    if ($status === 'READY') {
                        $this->isBusy = false;
                        ($this->onReadyCallback)($this);

                        continue;
                    }

                    $taskId = isset($data['task_id']) && is_string($data['task_id'])
                        ? $data['task_id']
                        : null;

                    if ($taskId === null || ! isset($this->pendingTasks[$taskId])) {
                        continue;
                    }

                    $promise = $this->pendingTasks[$taskId];

                    if ($status === 'OUTPUT') {
                        $output = $data['output'] ?? '';
                        echo \is_string($output) ? $output : '';
                    } elseif ($status === 'COMPLETED') {
                        $result = $data['result'] ?? null;

                        if (($data['result_serialized'] ?? false) === true && \is_string($result)) {
                            $decoded = base64_decode($result, true);
                            if ($decoded !== false) {
                                $result = unserialize($decoded);
                            }
                        }

                        unset($this->pendingTasks[$taskId]);
                        $promise->resolve($result);
                    } elseif ($status === 'ERROR') {
                        /** @var array<string, mixed> $data */
                        $exception = ExceptionHandler::createFromWorkerError($data, 'unknown');
                        unset($this->pendingTasks[$taskId]);
                        $promise->reject($exception);
                    }
                }
            } catch (\Throwable $e) {
                // Stream closed unexpectedly — treat as a crash
                $this->terminate();
                ($this->onCrashCallback)($this);
            } finally {
                $this->terminate();
            }
        });
    }

    /**
     * @return PromiseInterface<mixed>
     */
    public function submitTask(string $taskId, string $payload): PromiseInterface
    {
        $this->isBusy = true;

        /** @var Promise<mixed> $promise */
        $promise = new Promise();
        $this->pendingTasks[$taskId] = $promise;

        async(fn () => await($this->stdin->writeAsync($payload . PHP_EOL)));

        return $promise;
    }

    public function isBusy(): bool
    {
        return $this->isBusy;
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    public function terminate(): void
    {
        if (! $this->isAlive) {
            return;
        }

        $this->handleCrash();

        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID {$this->pid} 2>nul");
        } else {
            exec("pkill -9 -P {$this->pid} 2>/dev/null; kill -9 {$this->pid} 2>/dev/null");
        }

        $this->stdin->close();
        $this->stdout->close();
        $this->stderr->close();

        if (\is_resource($this->processResource)) {
            @proc_terminate($this->processResource);
            @proc_close($this->processResource);
        }
    }

    private function handleCrash(): void
    {
        $this->isAlive = false;
        $this->isBusy = false;

        foreach ($this->pendingTasks as $taskId => $promise) {
            $promise->reject(new \RuntimeException(
                "Persistent worker PID {$this->pid} crashed or stream closed while executing task {$taskId}."
            ));
        }

        $this->pendingTasks = [];
    }
}
