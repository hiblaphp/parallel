<?php

declare(strict_types=1);

use Hibla\Parallel\Internals\BackgroundProcess;

describe('BackgroundProcess', function () {

    $spawnDummyProcess = function (int $seconds = 2) {
        $php = PHP_BINARY;
        $code = "sleep($seconds);";

        $descriptors = [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ];

        $process = proc_open("$php -r \"$code\"", $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn dummy test process');
        }

        return [proc_get_status($process)['pid'], $process, $pipes];
    };

    it('returns the correct PID', function () {
        $bp = new BackgroundProcess(12345);

        expect($bp->getPid())->toBe(12345);
    });

    it('correctly reports if a process is running', function () use ($spawnDummyProcess) {
        [$pid, $resource, $pipes] = $spawnDummyProcess(5);

        $bp = new BackgroundProcess($pid);

        // Should be running immediately
        expect($bp->isRunning())->toBeTrue();

        // Kill it manually
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID $pid 2>nul");
        } else {
            posix_kill($pid, 9);
        }

        // Reap the zombie process so the PID is removed from the OS table
        proc_close($resource);

        expect($bp->isRunning())->toBeFalse();
    });

    it('can terminate a running process', function () use ($spawnDummyProcess) {
        [$pid, $resource, $pipes] = $spawnDummyProcess(10);

        $bp = new BackgroundProcess($pid);

        expect($bp->isRunning())->toBeTrue();

        $bp->terminate();

        // Reap the zombie
        proc_close($resource);

        expect($bp->isRunning())->toBeFalse();
    });
});
