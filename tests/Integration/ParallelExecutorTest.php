<?php

declare(strict_types=1);

namespace Tests\Feature;

use function Hibla\await;

use Hibla\Parallel\Interfaces\ParallelExecutorInterface;
use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\Internals\BackgroundProcess;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use Rcalicdan\ConfigLoader\Config;

describe('Parallel Feature Test', function () {
    $tempFiles = [];

    afterEach(function () use (&$tempFiles) {
        Config::reset();
        ProcessManager::setGlobal(null);

        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $tempFiles = [];
    });

    it('create() returns a ParallelExecutorInterface', function () {
        $executor = Parallel::task();
        expect($executor)->toBeInstanceOf(ParallelExecutorInterface::class);
    });

    it('successfully executes a basic task', function () {
        $result = await(
            Parallel::task()->run(fn () => 'Success')
        );

        expect($result)->toBe('Success');
    });

    it('respects a custom timeout and throws an exception', function () {
        await(
            Parallel::task()
                ->withTimeout(1)
                ->run(fn () => sleep(5))
        );
    })->throws(\RuntimeException::class, 'timed out after 1 seconds');

    it('runs without a timeout when configured', function () {
        $result = await(
            Parallel::task()
                ->withoutTimeout()
                ->run(function () {
                    usleep(100000);

                    return 'Completed';
                })
        );

        expect($result)->toBe('Completed');
    });

    it('fails when a custom memory limit is exceeded', function () {
        await(
            Parallel::task()
                ->withMemoryLimit('16M')
                ->run(fn () => str_repeat('a', 32 * 1024 * 1024))
        );
    })->throws(\Exception::class, 'Allowed memory size');

    it('succeeds when unlimited memory is configured', function () {
        $result = await(
            Parallel::task()
                ->withUnlimitedMemory()
                ->run(fn () => strlen(str_repeat('a', 10 * 1024 * 1024)))
        );

        expect($result)->toBe(10 * 1024 * 1024);
    });

    it('uses a custom bootstrap file for a task', function () use (&$tempFiles) {
        $bootstrapFile = sys_get_temp_dir() . '/test_bootstrap_' . uniqid() . '.php';
        $tempFiles[] = $bootstrapFile;

        file_put_contents($bootstrapFile, "<?php define('BOOTSTRAP_EXECUTED', 'yes');");

        $result = await(
            Parallel::task()
                ->withBootstrap($bootstrapFile)
                ->run(fn () => defined('BOOTSTRAP_EXECUTED') ? BOOTSTRAP_EXECUTED : 'no')
        );

        expect($result)->toBe('yes');
    });

    it('is immutable when chaining configuration methods on create()', function () {
        $base = Parallel::task()->withTimeout(1);
        $derived = $base->withTimeout(10);

        expect($base)->not->toBe($derived);

        $getTimeout = function (ParallelExecutorInterface $executor): int {
            $ref = new \ReflectionObject($executor);
            $prop = $ref->getProperty('timeoutSeconds');

            return $prop->getValue($executor);
        };

        expect($getTimeout($base))->toBe(1);
        expect($getTimeout($derived))->toBe(10);
    });

    it('can spawn a background process', function () use (&$tempFiles) {
        $proofFile = sys_get_temp_dir() . '/proof_' . uniqid() . '.txt';
        $tempFiles[] = $proofFile;

        $process = await(
            Parallel::task()
                ->spawn(function () use ($proofFile) {
                    file_put_contents($proofFile, 'spawned');
                })
        );

        expect($process)->toBeInstanceOf(BackgroundProcess::class);

        $attempts = 0;
        while (! file_exists($proofFile) && $attempts < 20) {
            usleep(100000);
            $attempts++;
        }

        expect(file_exists($proofFile))->toBeTrue();
        expect(file_get_contents($proofFile))->toBe('spawned');

        $process->terminate();
    });

    it('respects a custom maximum nesting level', function () {
        putenv('DEFER_NESTING_LEVEL=2');

        expect(
            fn () => Parallel::task()
                ->withMaxNestingLevel(2)
                ->spawn(fn () => true)
        )->toThrow(\RuntimeException::class, 'Already at maximum nesting level');

        $process = await(
            Parallel::task()
                ->withMaxNestingLevel(3)
                ->spawn(fn () => true)
        );

        expect($process)->toBeInstanceOf(BackgroundProcess::class);
        $process->terminate();

        putenv('DEFER_NESTING_LEVEL=');
    });

    it('pool() returns a ProcessPoolInterface', function () {
        $pool = Parallel::pool(size: 2);
        expect($pool)->toBeInstanceOf(ProcessPoolInterface::class);
        $pool->shutdown();
    });

    it('pool() throws when size is less than 1', function () {
        expect(fn () => Parallel::pool(size: 0))
            ->toThrow(\InvalidArgumentException::class, 'Pool size must be at least 1')
        ;
    });

    it('persistent pool executes a basic task', function () {
        $pool = Parallel::pool(size: 2);

        $result = await($pool->run(fn () => 'hello from pool'));

        expect($result)->toBe('hello from pool');
        $pool->shutdown();
    });

    it('persistent pool runs concurrent tasks in parallel', function () {
        $pool = Parallel::pool(size: 3);

        $results = await(Promise::all([
            $pool->run(fn () => 'task-1'),
            $pool->run(fn () => 'task-2'),
            $pool->run(fn () => 'task-3'),
        ]));

        expect($results)->toBe(['task-1', 'task-2', 'task-3']);
        $pool->shutdown();
    });

    it('persistent pool respects custom timeout', function () {
        $pool = Parallel::pool(size: 2)
            ->withTimeout(1)
        ;

        await($pool->run(fn () => sleep(5)));
    })->throws(\RuntimeException::class);

    it('persistent pool respects custom memory limit', function () {
        $pool = Parallel::pool(size: 2)
            ->withMemoryLimit('16M')
        ;

        await($pool->run(fn () => str_repeat('a', 32 * 1024 * 1024)));
    })->throws(\Exception::class, 'Allowed memory size');

    it('persistent pool reuses worker processes across batches', function () {
        $pool = Parallel::pool(size: 2);

        $batch1 = await(Promise::all([
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
        ]));

        $batch2 = await(Promise::all([
            $pool->run(fn () => getmypid()),
            $pool->run(fn () => getmypid()),
        ]));

        $initialPids = array_unique($batch1);
        $reusedPids = array_unique($batch2);
        sort($initialPids);
        sort($reusedPids);

        expect($reusedPids)->toBe($initialPids);
        $pool->shutdown();
    });

    it('persistent pool rejects tasks after shutdown', function () {
        $pool = Parallel::pool(size: 2);
        $pool->shutdown();

        $results = await(Promise::allSettled([
            $pool->run(fn () => 'should not run'),
        ]));

        expect($results[0]->isRejected())->toBeTrue();
        expect($results[0]->reason->getMessage())->toContain('shutdown');
    });

    it('persistent pool uses a custom bootstrap file', function () use (&$tempFiles) {
        $bootstrapFile = sys_get_temp_dir() . '/test_pool_bootstrap_' . uniqid() . '.php';
        $tempFiles[] = $bootstrapFile;

        file_put_contents($bootstrapFile, "<?php define('POOL_BOOTSTRAP', 'pool_yes');");

        $pool = Parallel::pool(size: 2)
            ->withBootstrap($bootstrapFile)
        ;

        $result = await($pool->run(
            fn () => defined('POOL_BOOTSTRAP') ? POOL_BOOTSTRAP : 'no'
        ));

        expect($result)->toBe('pool_yes');
        $pool->shutdown();
    });

    it('persistent pool is immutable when chaining configuration methods', function () {
        $base = Parallel::pool(size: 2)->withTimeout(5);
        $derived = $base->withTimeout(30);

        expect($base)->not->toBe($derived);

        $getTimeout = function (ProcessPoolInterface $executor): int {
            $ref = new \ReflectionObject($executor);
            $prop = $ref->getProperty('timeoutSeconds');

            return $prop->getValue($executor);
        };

        expect($getTimeout($base))->toBe(5);
        expect($getTimeout($derived))->toBe(30);

        $base->shutdown();
        $derived->shutdown();
    });

    it('persistent pool BLOCKS a nested task that exceeds the max nesting level', function () {
        $pool = Parallel::pool(size: 1)
            ->withMaxNestingLevel(1)
        ;

        $task = function () {
            await(
                Parallel::task()->run(fn () => 'This should not execute')
            );
        };

        try {
            await($pool->run($task));
        } finally {
            $pool->shutdown();
        }
    })->throws(\RuntimeException::class, 'Already at maximum nesting level');

    it('persistent pool ALLOWS a nested task when within the max nesting level', function () {
        $pool = Parallel::pool(size: 1)
            ->withMaxNestingLevel(2)
        ;

        $task = function () {
            return await(
                Parallel::task()->run(fn () => 'Nested success')
            );
        };

        $result = await($pool->run($task));

        expect($result)->toBe('Nested success');

        $pool->shutdown();
    });
});
