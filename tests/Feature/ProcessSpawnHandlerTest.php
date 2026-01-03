<?php

use Hibla\Parallel\Handlers\ProcessSpawnHandler;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Parallel\Process;
use Hibla\Parallel\BackgroundProcess;
use Rcalicdan\Serializer\CallbackSerializationManager;
use Rcalicdan\ConfigLoader\Config;

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
                try { $process->terminate(); } catch (Throwable $e) {}
            }
        }
        $activeProcesses = [];
        Config::reset();
    });

    test('it can spawn a Streamed Task (worker.php)', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer, $logger] = $setupHandler();

        $taskId = 'test_stream_' . uniqid();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        /** @var Process $process */
        $process = $handler->spawnStreamedTask(
            taskId: $taskId,
            callback: fn() => 'Hello World',
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            loggingEnabled: true,
            timeoutSeconds: 5
        );

        // Register for cleanup
        $activeProcesses[] = $process;

        // Assertions
        expect($process)->toBeInstanceOf(Process::class);
        expect($process->getPid())->toBeInt()->toBeGreaterThan(0);
        expect($process->getTaskId())->toBe($taskId);
        
        // Critical: Check if OS actually sees the process
        // isRunning checks the OS process table
        expect($process->isRunning())->toBeTrue();
    });

    test('it can spawn a Background Task (worker_background.php)', function () use ($setupHandler, &$activeProcesses) {
        [$handler, $utils, $serializer, $logger] = $setupHandler();

        $taskId = 'test_bg_' . uniqid();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        /** @var BackgroundProcess $process */
        $process = $handler->spawnBackgroundTask(
            taskId: $taskId,
            callback: fn() => 'Background Job',
            frameworkInfo: $frameworkInfo,
            serializationManager: $serializer,
            loggingEnabled: true,
            timeoutSeconds: 5
        );

        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(BackgroundProcess::class);
        expect($process->getPid())->toBeInt()->toBeGreaterThan(0);
        
        // Note: Background tasks might finish extremely quickly, so isRunning() 
        // *might* be false by the time we check it if the machine is fast and task is empty.
        // However, with the overhead of PHP boot, it should usually be true immediately.
        expect($process->isRunning())->toBeTrue();
    });

    test('it validates timeout values', function () use ($setupHandler) {
        [$handler, $utils, $serializer] = $setupHandler();
        $frameworkInfo = $utils->getFrameworkBootstrap();

        // Too small
        expect(fn() => $handler->spawnBackgroundTask(
            'id', fn() => true, $frameworkInfo, $serializer, false, 0
        ))->toThrow(InvalidArgumentException::class, 'Timeout must be at least 1 second');

        // Too large
        expect(fn() => $handler->spawnBackgroundTask(
            'id', fn() => true, $frameworkInfo, $serializer, false, 90000
        ))->toThrow(InvalidArgumentException::class, 'Timeout cannot exceed 86400 seconds');
    });

    test('it correctly resolves the worker script paths', function () use ($setupHandler) {
        // This tests the getWorkerPath private logic indirectly via execution success
        // If the path was wrong, proc_open would likely succeed but the process would exit immediately 
        // with code 1 or print "Could not open input file".
        
        [$handler, $utils, $serializer] = $setupHandler();
        
        // We inject a closure that sleeps for 1 second to ensure the process stays alive
        // long enough for us to verify it launched the correct script.
        $process = $handler->spawnStreamedTask(
            'test_path', 
            function() { usleep(100000); }, 
            $utils->getFrameworkBootstrap(), 
            $serializer, 
            false, 
            5
        );
        
        // If the script path was invalid, PHP usually exits almost instantly.
        // We check that it is initially running.
        expect($process->isRunning())->toBeTrue();
        
        $process->terminate();
    });
});