<?php

declare(strict_types=1);

namespace Tests\Feature;

use function Hibla\await;

use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\ParallelExecutor;
use Rcalicdan\ConfigLoader\Config;

describe('ParallelExecutor Feature Test', function () {
    $tempFiles = [];

    afterEach(function () use (&$tempFiles) {
        Config::reset();

        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $tempFiles = [];
    });

    it('successfully executes a basic task', function () {
        $result = await(
            ParallelExecutor::create()->run(fn() => 'Success')
        );

        expect($result)->toBe('Success');
    });

    it('respects a custom timeout and throws an exception', function () {
        $task = fn() => sleep(5);

        await(
            ParallelExecutor::create()
                ->withTimeout(1)
                ->run($task)
        );
    })->throws(\RuntimeException::class, 'timed out after 1 seconds');

    it('runs without a timeout when configured', function () {
        $result = await(
            ParallelExecutor::create()
                ->withoutTimeout()
                ->run(function () {
                    usleep(100000);
                    return 'Completed';
                })
        );

        expect($result)->toBe('Completed');
    });

    it('fails when a custom memory limit is exceeded', function () {
        $task = function () {
            return str_repeat('a', 32 * 1024 * 1024);
        };

        await(
            ParallelExecutor::create()
                ->withMemoryLimit('16M')
                ->run($task)
        );
    })->throws(\Exception::class, 'Allowed memory size');

    it('succeeds when unlimited memory is configured', function () {
        $result = await(
            ParallelExecutor::create()
                ->withUnlimitedMemory()
                ->run(function () {
                    // Allocate a reasonable amount that would fail with the default limit
                    return strlen(str_repeat('a', 10 * 1024 * 1024));
                })
        );

        expect($result)->toBe(10 * 1024 * 1024);
    });

    it('enables logging for a single task when global logging is off', function () {
        // Disable logging globally
        Config::setFromRoot('hibla_parallel', 'logging.enabled', false);

        $logDir = sys_get_temp_dir() . '/hibla_executor_test_logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        // Configure BackgroundLogger to use our temp dir
        Config::setFromRoot('hibla_parallel', 'logging.directory', $logDir);

        $process = await(
            ParallelExecutor::create()
                ->withLogging()
                ->spawn(fn() => true)
        );

        $statusFile = $logDir . DIRECTORY_SEPARATOR . $process->getTaskId() . '.json';
        expect(file_exists($statusFile))->toBeTrue();

        // Cleanup
        $process->terminate();
        @unlink($statusFile);
        @rmdir($logDir);
    });

    it('disables logging for a single task when global logging is on', function () {
        // Enable logging globally
        Config::setFromRoot('hibla_parallel', 'logging.enabled', true);

        $logDir = sys_get_temp_dir() . '/hibla_executor_test_logs';
        Config::setFromRoot('hibla_parallel', 'logging.directory', $logDir);

        $process = await(
            ParallelExecutor::create()
                ->withoutLogging()
                ->spawn(fn() => true)
        );

        $statusFile = $logDir . DIRECTORY_SEPARATOR . $process->getTaskId() . '.json';
        expect(file_exists($statusFile))->toBeFalse();

        // Cleanup
        $process->terminate();
    });

    it('uses a custom bootstrap file for a task', function () use (&$tempFiles) {
        $bootstrapFile = sys_get_temp_dir() . '/test_bootstrap_' . uniqid() . '.php';
        $tempFiles[] = $bootstrapFile;

        $bootstrapCode = "<?php define('BOOTSTRAP_EXECUTED', 'yes');";
        file_put_contents($bootstrapFile, $bootstrapCode);

        $result = await(
            ParallelExecutor::create()
                ->withBootstrap($bootstrapFile)
                ->run(fn() => defined('BOOTSTRAP_EXECUTED') ? BOOTSTRAP_EXECUTED : 'no')
        );

        expect($result)->toBe('yes');
    });

    it('is immutable when chaining configuration methods', function () {
        $baseExecutor = ParallelExecutor::create()->withTimeout(1);
        $derivedExecutor = $baseExecutor->withTimeout(10);

        expect($baseExecutor)->not->toBe($derivedExecutor);

        $getTimeout = function (ParallelExecutor $executor): int {
            $ref = new \ReflectionObject($executor);
            $prop = $ref->getProperty('timeoutSeconds');
            return $prop->getValue($executor);
        };

        expect($getTimeout($baseExecutor))->toBe(1);
        expect($getTimeout($derivedExecutor))->toBe(10);
    });

    it('can spawn a background process', function () use (&$tempFiles) {
        $proofFile = sys_get_temp_dir() . '/proof_' . uniqid() . '.txt';
        $tempFiles[] = $proofFile;

        $process = await(
            ParallelExecutor::create()
                ->spawn(function () use ($proofFile) {
                    file_put_contents($proofFile, 'spawned');
                })
        );

        expect($process)->toBeInstanceOf(BackgroundProcess::class);

        $attempts = 0;
        while (!file_exists($proofFile) && $attempts < 20) {
            usleep(100000);
            $attempts++;
        }

        expect(file_exists($proofFile))->toBeTrue();
        expect(file_get_contents($proofFile))->toBe('spawned');

        $process->terminate();
    });
});
