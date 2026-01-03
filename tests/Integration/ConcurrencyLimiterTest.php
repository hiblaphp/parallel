<?php

use Hibla\Parallel\Managers\ProcessManager;
use Hibla\Parallel\BackgroundProcess;
use Rcalicdan\ConfigLoader\Config;
use Hibla\Promise\Promise;

use function Hibla\parallelFn;
use function Hibla\spawnFn;
use function Hibla\await;

describe('Concurrency Limiters Integration', function () {
    it('throttles parallel execution using Promise::map concurrency', function () {
        $start = microtime(true);
        $items = [1, 2, 3, 4];
        
        $worker = parallelFn(function($n) {
            usleep(200000); 
            return $n * 2;
        });

        $results = await(Promise::map($items, $worker, concurrency: 2));
        $duration = microtime(true) - $start;

        expect($results)->toBe([2, 4, 6, 8]);
        
        expect($duration)->toBeGreaterThan(0.3); 

        expect($duration)->toBeLessThan(2.0);     
    });

    it('processes tasks in batches', function () {
        $start = microtime(true);

        $sleeper = parallelFn(fn() => usleep(100000)); 

        $tasks = [$sleeper, $sleeper, $sleeper, $sleeper];

        await(Promise::batch($tasks, batchSize: 2));

        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThan(0.15);

        expect($duration)->toBeLessThan(1.5);
    });


    it('limits active execution with Promise::concurrent', function () {
        $start = microtime(true);

        $sleeper = parallelFn(fn() => usleep(150000)); 
        
        $tasks = [$sleeper, $sleeper, $sleeper];

        await(Promise::concurrent($tasks, concurrency: 1));

        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThan(0.4);
    });

    it('spawnFn creates a background process factory', function () {
        $file = sys_get_temp_dir() . '/hibla_spawnfn_' . uniqid();
        
        $writer = spawnFn(function($path, $content) {
            file_put_contents($path, $content);
        });

        /** @var BackgroundProcess $process */
        $process = await($writer($file, 'hello spawnFn'));

        expect($process)->toBeInstanceOf(BackgroundProcess::class);
        expect($process->isRunning())->toBeTrue();

        $waited = 0;
        while (!file_exists($file) && $waited < 20) {
            usleep(100000);
            $waited++;
        }

        expect(file_exists($file))->toBeTrue();
        expect(file_get_contents($file))->toBe('hello spawnFn');

        @unlink($file);
        $process->terminate();
    });

    it('spawnFn works with Promise::map (Spawning Throttling)', function () {
        $items = range(1, 10);

        $spawner = spawnFn(fn($i) => true);

        $processes = await(Promise::map($items, $spawner, concurrency: 5));

        expect($processes)->toHaveCount(10);
        expect($processes[0])->toBeInstanceOf(BackgroundProcess::class);
        
        foreach ($processes as $p) {
            $p->terminate();
        }
    });
});