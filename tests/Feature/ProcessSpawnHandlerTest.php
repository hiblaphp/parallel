<?php

declare(strict_types=1);

use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Handlers\ProcessSpawnHandler;
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
            if ($process instanceof Process || $process instanceof BackgroundProcess) {
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
        [$handler, $utils, $serializer, $logger] = $setupHandler();
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
        [$handler, $utils, $serializer, $logger] = $setupHandler();
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

    it('validates timeout values', function () use ($setupHandler) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        expect(fn () => $handler->spawnBackgroundTask(
            'id',
            fn () => true,
            $frameworkInfo,
            $serializer,
            false,
            0
        ))->toThrow(InvalidArgumentException::class, 'Timeout must be at least 1 second');

        expect(fn () => $handler->spawnBackgroundTask(
            'id',
            fn () => true,
            $frameworkInfo,
            $serializer,
            false,
            90000
        ))->toThrow(InvalidArgumentException::class, 'Timeout cannot exceed 86400 seconds');
    });

    it('correctly resolves the worker script paths', function () use ($setupHandler) {
        [$handler, $utils, $serializer] = $setupHandler();

        $process = $handler->spawnStreamedTask(
            'test_path',
            function () { usleep(100000); },
            $utils->getFrameworkBootstrap(),
            $serializer,
            false,
            5
        );

        expect($process->isRunning())->toBeTrue();

        $process->terminate();
    });
});
