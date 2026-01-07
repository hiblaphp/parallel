<?php

declare(strict_types=1);

use function Hibla\await;

use Hibla\Parallel\BackgroundProcess;
use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\Process;

use Rcalicdan\ConfigLoader\Config;

describe('ProcessManager Real Integration', function () {
    $activeProcesses = [];

    afterEach(function () use (&$activeProcesses) {
        foreach ($activeProcesses as $process) {
            try {

                $process->terminate();
            } catch (Throwable $e) {
                // Ignore cleanup errors
            }
        }
        $activeProcesses = [];

        ProcessManager::setGlobal(null);
        Config::reset();
        putenv('DEFER_NESTING_LEVEL');
    });

    it('implements the singleton pattern via getGlobal', function () {
        $instance1 = ProcessManager::getGlobal();
        $instance2 = ProcessManager::getGlobal();

        expect($instance1)->toBe($instance2);
        expect($instance1)->toBeInstanceOf(ProcessManager::class);
    });

    it('allows direct instantiation with custom settings', function () {
        $manager = new ProcessManager(maxSpawnsPerSecond: 10);

        $ref = new ReflectionClass($manager);
        $prop = $ref->getProperty('maxSpawnsPerSecond');
        $prop->setAccessible(true);

        expect($prop->getValue($manager))->toBe(10);
    });

    it('prevents spawning tasks when nesting level is detected', function () {
        putenv('DEFER_NESTING_LEVEL=1000');

        $manager = new ProcessManager();

        expect(fn () => $manager->spawnStreamedTask(fn () => true))
            ->toThrow(RuntimeException::class)
        ;
    });

    it('orchestrates a streamed task that returns complex data', function () use (&$activeProcesses) {
        $manager = new ProcessManager();

        $process = $manager->spawnStreamedTask(fn () => [
            'status' => 'ok',
            'values' => [1, 2, 3],
        ]);
        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(Process::class);
        expect($process->isRunning())->toBeTrue();

        $result = await($process->getResult());

        expect($result)->toBeArray();
        expect($result['status'])->toBe('ok');
        expect($result['values'])->toBe([1, 2, 3]);
    });

    it('orchestrates a streamed task that throws an exception (Error Propagation)', function () use (&$activeProcesses) {
        $manager = new ProcessManager();

        $process = $manager->spawnStreamedTask(function () {
            throw new InvalidArgumentException('Something went wrong in the worker');
        });
        $activeProcesses[] = $process;

        expect(fn () => await($process->getResult()))
            ->toThrow(InvalidArgumentException::class, 'Something went wrong in the worker')
        ;
    });

    it('orchestrates a background task that performs side-effects', function () use (&$activeProcesses) {
        $manager = new ProcessManager();

        $proofFile = sys_get_temp_dir() . '/hibla_bg_proof_' . uniqid();

        $process = $manager->spawnBackgroundTask(function () use ($proofFile) {
            usleep(10000);
            file_put_contents($proofFile, 'I ran successfully');
        });

        $activeProcesses[] = $process;

        expect($process)->toBeInstanceOf(BackgroundProcess::class);
        expect($process->isRunning())->toBeTrue();

        $waited = 0;
        while (! file_exists($proofFile) && $waited < 20) {
            usleep(100000);
            $waited++;
        }

        expect(file_exists($proofFile))->toBeTrue('Background task failed to create proof file');
        expect(file_get_contents($proofFile))->toBe('I ran successfully');

        @unlink($proofFile);
    });

    it('enforces rate limiting on real background tasks', function () use (&$activeProcesses) {
        $limit = 5;
        $manager = new ProcessManager(maxSpawnsPerSecond: $limit);
        $limitReached = false;

        try {
            for ($i = 0; $i < ($limit + 2); $i++) {
                $process = $manager->spawnBackgroundTask(fn () => true);
                $activeProcesses[] = $process;
            }
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Safety Limit')) {
                $limitReached = true;
            } else {
                throw $e;
            }
        }

        expect($limitReached)
            ->toBeTrue("Failed to trigger Safety Limit. Expected exception after $limit spawns.")
        ;

    });

    it('respects the spawn limit defined in configuration', function () use (&$activeProcesses) {
        Config::setFromRoot('hibla_parallel', 'background_process.spawn_limit_per_second', 2);

        $manager = new ProcessManager();

        for ($i = 0; $i < 2; $i++) {
            $activeProcesses[] = $manager->spawnBackgroundTask(fn () => true);
        }

        expect(fn () => $manager->spawnBackgroundTask(fn () => true))
            ->toThrow(RuntimeException::class, 'Safety Limit: Cannot spawn more than 2')
        ;
    });
});
