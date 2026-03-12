<?php

declare(strict_types=1);

use function Hibla\async;

use function Hibla\parallel;

use Hibla\Promise\Promise;

describe('Parallel and Async Concurrency', function () {

    it('runs parallel and async tasks concurrently and completes under 2 seconds', function () {
        $output = [];
        $start = microtime(true);

        Promise::all([
            parallel(fn () => sleep(1))->then(function () use (&$output) {
                $output[] = 1;
            }),
            parallel(fn () => sleep(1))->then(function () use (&$output) {
                $output[] = 2;
            }),
            parallel(fn () => sleep(1))->then(function () use (&$output) {
                $output[] = 3;
            }),
            async(fn () => Hibla\sleep(0.5))->then(function () use (&$output) {
                $output[] = 4;
            }),
            async(fn () => Hibla\sleep(0.5))->then(function () use (&$output) {
                $output[] = 5;
            }),
            async(fn () => Hibla\sleep(0.5))->then(function () use (&$output) {
                $output[] = 6;
            }),
        ])->wait();

        $elapsed = microtime(true) - $start;

        expect($output)->toHaveCount(6);

        $asyncItems = array_values(array_filter($output, fn ($v) => in_array($v, [4, 5, 6])));
        $parallelItems = array_values(array_filter($output, fn ($v) => in_array($v, [1, 2, 3])));

        expect($asyncItems)->toContain(4, 5, 6);
        expect($parallelItems)->toContain(1, 2, 3);

        $lastAsyncPosition = max(array_keys(array_filter($output, fn ($v) => in_array($v, [4, 5, 6]))));
        $firstParallelPosition = min(array_keys(array_filter($output, fn ($v) => in_array($v, [1, 2, 3]))));

        expect($lastAsyncPosition)->toBeLessThan($firstParallelPosition);
        expect($elapsed)->toBeLessThan(2.0);
    });
});
