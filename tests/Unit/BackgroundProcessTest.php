<?php

declare(strict_types=1);

use Hibla\Parallel\BackgroundProcess;

describe('BackgroundProcess', function () {

    $spawnDummyProcess = function (int $seconds = 2) {
        $php = PHP_BINARY;
        // Increase sleep slightly to prevent natural timeout during test execution
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

    it('returns the correct task ID and PID', function () {
        $bp = new BackgroundProcess('task_basic', 12345);

        expect($bp->getTaskId())->toBe('task_basic');
        expect($bp->getPid())->toBe(12345);
    });

    it('correctly reports if a process is running', function () use ($spawnDummyProcess) {
        [$pid, $resource, $pipes] = $spawnDummyProcess(5); // Sleep 5 seconds

        $bp = new BackgroundProcess('task_check', $pid);

        // 1. Should be running immediately
        expect($bp->isRunning())->toBeTrue();

        // 2. Kill it manually
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /PID $pid 2>nul");
        } else {
            posix_kill($pid, 9);
        }

        // 3. CRITICAL: Reap the zombie process.
        // On Linux, the parent (this test) must read the exit code
        // for the PID to be removed from the OS table.
        proc_close($resource);

        // 4. Now it should be truly gone
        expect($bp->isRunning())->toBeFalse();
    });

    it('can terminate a running process', function () use ($spawnDummyProcess) {
        [$pid, $resource, $pipes] = $spawnDummyProcess(10);

        $bp = new BackgroundProcess('task_kill', $pid);

        expect($bp->isRunning())->toBeTrue();

        // Terminate calls kill -9
        $bp->terminate();

        // Reap the zombie
        proc_close($resource);

        expect($bp->isRunning())->toBeFalse();
    });

    it('updates the status file upon termination if logging is enabled', function () use ($spawnDummyProcess) {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_bg_test_' . uniqid() . '.json';
        file_put_contents($tempFile, json_encode([
            'task_id' => 'task_log',
            'status' => 'RUNNING',
        ]));

        [$pid, $resource, $pipes] = $spawnDummyProcess(10);

        $bp = new BackgroundProcess('task_log', $pid, $tempFile, loggingEnabled: true);

        $bp->terminate();

        // Reap
        proc_close($resource);

        expect(file_exists($tempFile))->toBeTrue();

        $data = json_decode(file_get_contents($tempFile), true);

        expect($data['status'])->toBe('CANCELLED');
        expect($data['message'])->toContain('terminated');

        unlink($tempFile);
    });

    it('does not update status file if logging is disabled', function () use ($spawnDummyProcess) {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_bg_no_log_' . uniqid() . '.json';
        $originalContent = json_encode(['status' => 'RUNNING']);
        file_put_contents($tempFile, $originalContent);

        [$pid, $resource, $pipes] = $spawnDummyProcess(10);

        $bp = new BackgroundProcess('task_no_log', $pid, $tempFile, loggingEnabled: false);

        $bp->terminate();

        // Reap
        proc_close($resource);

        expect(file_get_contents($tempFile))->toBe($originalContent);

        unlink($tempFile);
    });
});
