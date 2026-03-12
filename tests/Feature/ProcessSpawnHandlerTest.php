<?php

declare(strict_types=1);

use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\PersistentProcess;
use Hibla\Parallel\Process;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\ConfigLoader\Config;
use Rcalicdan\Serializer\CallbackSerializationManager;

describe('ProcessSpawnHandler Feature Test', function () {

    $setupHandler = function () {
        $utils = new SystemUtilities();
        $logger = new BackgroundLogger(enableDetailedLogging: true, customLogDir: sys_get_temp_dir() . '/hibla_spawn_test');
        $serializer = new CallbackSerializationManager();
        $handler = new ProcessSpawnHandler($utils, $logger);

        return [$handler, $utils, $serializer, $logger];
    };

    $activeProcesses = [];

    afterEach(function () use (&$activeProcesses) {
        foreach ($activeProcesses as $process) {
            if ($process instanceof Process || $process instanceof BackgroundProcess || $process instanceof PersistentProcess) {
                try {
                    $process->terminate();
                } catch (Throwable $e) {
                }
            }
        }
        $activeProcesses = [];
        Config::reset();
    });

    it('can spawn a Streamed Task (worker.php)', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $taskId = 'test_stream_' . uniqid();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        /** @var Process $process */
        $process = $handler->spawnStreamedTask(
            taskId: $taskId,
            callback: fn () => 'Hello World',
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            loggingEnabled: true,
            timeoutSeconds: 5
        );

        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(Process::class);
        expect($process->getPid())->toBeInt()->toBeGreaterThan(0);
        expect($process->getTaskId())->toBe($taskId);
        expect($process->isRunning())->toBeTrue();
    });

    it('can spawn a Background Task (worker_background.php)', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $taskId = 'test_bg_' . uniqid();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        $process = $handler->spawnBackgroundTask(
            taskId: $taskId,
            callback: fn () => usleep(200000),
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            loggingEnabled: true,
            timeoutSeconds: 5
        );

        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(BackgroundProcess::class);
        expect($process->getPid())->toBeInt()->toBeGreaterThan(0);
        expect($process->isRunning())->toBeTrue();
    });

    it('can spawn a Persistent Worker (worker_persistent.php)', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        $process = $handler->spawnPersistentWorker(
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            memoryLimit: '128M',
            maxNestingLevel: 3
        );

        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(PersistentProcess::class);
        expect($process->isAlive())->toBeTrue();
        expect($process->isBusy())->toBeTrue();
    });

    it('persistent worker becomes ready after receiving boot payload', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        $process = $handler->spawnPersistentWorker(
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            memoryLimit: '128M',
            maxNestingLevel: 3
        );

        $activeProcesses[] = $process;

        $readyReceived = false;

        $process->startReadLoop(
            onReadyCallback: function (PersistentProcess $worker) use (&$readyReceived) {
                $readyReceived = true;
            },
            onCrashCallback: function (PersistentProcess $worker) {
            }
        );

        // Tick the event loop until READY is received or timeout
        $start = microtime(true);
        while (! $readyReceived && (microtime(true) - $start) < 5) {
            Hibla\EventLoop\Loop::runOnce();
            usleep(10000);
        }

        expect($readyReceived)->toBeTrue('Persistent worker did not send READY frame within 5 seconds');
        expect($process->isAlive())->toBeTrue();
        expect($process->isBusy())->toBeFalse();
    });

    it('persistent worker executes a task and resolves its promise', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        $process = $handler->spawnPersistentWorker(
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            memoryLimit: '128M',
            maxNestingLevel: 3
        );

        $activeProcesses[] = $process;

        $result = null;
        $rejected = null;

        $process->startReadLoop(
            onReadyCallback: function (PersistentProcess $worker) use ($serializer, &$result, &$rejected) {
                $taskId = 'persistent_task_' . uniqid();
                $payload = json_encode([
                    'task_id' => $taskId,
                    'serialized_callback' => $serializer->serializeCallback(fn () => 'persistent result'),
                ]);

                $worker->submitTask($taskId, (string)$payload)
                    ->then(
                        function ($value) use (&$result) {
                            $result = $value;
                        },
                        function ($reason) use (&$rejected) {
                            $rejected = $reason;
                        }
                    )
                ;
            },
            onCrashCallback: function (PersistentProcess $worker) {
            }
        );

        $start = microtime(true);
        while ($result === null && $rejected === null && (microtime(true) - $start) < 5) {
            Hibla\EventLoop\Loop::runOnce();
            usleep(10000);
        }

        expect($rejected)->toBeNull('Task was unexpectedly rejected: ' . ($rejected?->getMessage() ?? ''));
        expect($result)->toBe('persistent result');
    });

    it('persistent worker fires onCrashCallback when worker calls exit()', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        $process = $handler->spawnPersistentWorker(
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            memoryLimit: '128M',
            maxNestingLevel: 3
        );

        $activeProcesses[] = $process;

        $crashFired = false;

        $process->startReadLoop(
            onReadyCallback: function (PersistentProcess $worker) use ($serializer) {
                $taskId = 'crash_task_' . uniqid();
                $payload = json_encode([
                    'task_id' => $taskId,
                    'serialized_callback' => $serializer->serializeCallback(function () {
                        exit(1);
                    }),
                ]);
                $worker->submitTask($taskId, (string)$payload);
            },
            onCrashCallback: function (PersistentProcess $worker) use (&$crashFired) {
                $crashFired = true;
            }
        );

        $start = microtime(true);
        while (! $crashFired && (microtime(true) - $start) < 5) {
            Hibla\EventLoop\Loop::runOnce();
            usleep(10000);
        }

        expect($crashFired)->toBeTrue('onCrashCallback was not fired after worker exit(1)');
        expect($process->isAlive())->toBeFalse();
    });

    it('validates timeout values', function () use ($setupHandler) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        expect(fn () => $handler->spawnBackgroundTask(
            'id',
            fn () => true,
            $frameworkInfo,
            $serializer,
            false,
            -1
        ))->toThrow(InvalidArgumentException::class);
    });

    it('correctly resolves the worker script paths', function () use ($setupHandler) {
        [$handler, $utils, $serializer] = $setupHandler();

        $process = $handler->spawnStreamedTask(
            'test_path',
            function () {
                usleep(100000);
            },
            $utils->getFrameworkBootstrap(),
            $serializer,
            false,
            5
        );

        expect($process->isRunning())->toBeTrue();
        $process->terminate();
    });
});
