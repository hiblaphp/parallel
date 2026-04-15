<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\Serializer\CallbackSerializationManager;

describe('Persistent Worker Script Integration', function () {
    $projectRoot = dirname(__DIR__, 2);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    $workerScript = $projectRoot . '/src/worker_persistent.php';
    $serializer = new CallbackSerializationManager();

    /**
     * Boots a persistent worker and returns[$process, $pipes, $stdoutFile].
     * The caller is responsible for closing/terminating.
     */
    $bootWorker = function () use ($workerScript, $autoloadPath): array {
        $phpBinary = SystemUtilities::getPhpBinary();
        $stdoutFile = sys_get_temp_dir() . '/hibla_persistent_stdout_' . uniqid() . '.log';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($workerScript), $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn persistent worker');
        }

        $bootPayload = json_encode([
            'autoload_path' => $autoloadPath,
            'framework_bootstrap' => null,
            'framework_bootstrap_callback' => null,
            'memory_limit' => '128M',
        ]);

        fwrite($pipes[0], $bootPayload . PHP_EOL);
        fflush($pipes[0]);

        $start = microtime(true);
        while (true) {
            if (microtime(true) - $start > 5) {
                $pid = proc_get_status($process)['pid'];
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("taskkill /F /T /PID {$pid} 2>nul");
                } else {
                    exec("pkill -9 -P {$pid} 2>/dev/null; kill -9 {$pid} 2>/dev/null");
                }
                proc_close($process);

                throw new RuntimeException('Persistent worker did not send READY within 5 seconds');
            }
            $content = file_get_contents($stdoutFile);
            if ($content && str_contains($content, '"READY"')) {
                break;
            }
            usleep(20000);
        }

        return [$process, $pipes, $stdoutFile];
    };

    /**
     * Submits a single task to an already-booted worker and waits for a
     * terminal frame (COMPLETED, ERROR, or CRASHED). Returns all parsed frames.
     *
     * @return array<int, array<string, mixed>>
     */
    $submitTask = function (mixed $process, array $pipes, string $stdoutFile, callable $task, string $taskId) use ($serializer): array {
        $payload = json_encode([
            'task_id' => $taskId,
            'serialized_callback' => $serializer->serializeCallback($task),
        ]);

        $bytesBefore = strlen((string)file_get_contents($stdoutFile));

        fwrite($pipes[0], $payload . PHP_EOL);
        fflush($pipes[0]);

        $start = microtime(true);
        while (true) {
            if (microtime(true) - $start > 10) {
                throw new RuntimeException("Task {$taskId} timed out after 10 seconds");
            }

            $raw = (string)file_get_contents($stdoutFile);
            $newPart = substr($raw, $bytesBefore);
            $newPart = str_replace("\r\n", "\n", $newPart);
            $lines = array_filter(explode("\n", $newPart));

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if (! is_array($data)) {
                    continue;
                }
                $status = $data['status'] ?? '';
                if (in_array($status, ['COMPLETED', 'ERROR', 'CRASHED', 'READY'], true)) {
                    $frames = [];
                    foreach ($lines as $l) {
                        $d = json_decode($l, true);
                        if (is_array($d)) {
                            $frames[] = $d;
                        }
                    }

                    return $frames;
                }
            }

            usleep(20000);
        }
    };

    /**
     * Tears down a worker process cleanly.
     */
    $teardown = function (mixed $process, array $pipes, string $stdoutFile): void {
        if (is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        if (is_resource($process)) {
            $pid = proc_get_status($process)['pid'];
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /F /T /PID {$pid} 2>nul");
            } else {
                exec("pkill -9 -P {$pid} 2>/dev/null; kill -9 {$pid} 2>/dev/null");
            }
            proc_close($process);
        }
        @unlink($stdoutFile);
    };

    it('boots and sends a READY frame', function () use ($bootWorker, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $content = (string)file_get_contents($stdoutFile);
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $content)));

        $readyFound = false;
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data) && ($data['status'] ?? '') === 'READY') {
                $readyFound = true;

                break;
            }
        }

        expect($readyFound)->toBeTrue('Worker did not emit a READY frame after boot');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('executes a task and returns COMPLETED with result', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, fn () => 'Hello from persistent worker', 'task-completed');
        $terminal = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'COMPLETED'));

        expect($terminal)->not->toBeEmpty('No COMPLETED frame received');
        expect($terminal[0]['result'])->toBe('Hello from persistent worker');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('captures echoed output as OUTPUT frames', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, function () {
            echo 'line one';
            echo 'line two';

            return true;
        }, 'task-output');

        $outputFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'OUTPUT'));
        $completed = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'COMPLETED'));

        expect($outputFrames)->not->toBeEmpty('No OUTPUT frames received');
        expect($completed)->not->toBeEmpty('No COMPLETED frame received');

        $combined = implode('', array_column($outputFrames, 'output'));
        expect($combined)->toContain('line one');
        expect($combined)->toContain('line two');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('returns ERROR frame when task throws an exception', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, function () {
            throw new InvalidArgumentException('Persistent worker exception');
        }, 'task-error');
        $errorFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'ERROR'));

        expect($errorFrames)->not->toBeEmpty('No ERROR frame received');
        expect($errorFrames[0]['class'])->toBe(InvalidArgumentException::class);
        expect($errorFrames[0]['message'])->toBe('Persistent worker exception');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('sends READY again after completing a task (worker is reusable)', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        // First task
        $frames1 = $submitTask($process, $pipes, $stdoutFile, fn () => 'first', 'task-reuse-1');
        $ready1 = array_values(array_filter($frames1, fn ($f) => ($f['status'] ?? '') === 'READY'));
        expect($ready1)->not->toBeEmpty('Worker did not send READY after first task');

        // Second task on the same worker process
        $frames2 = $submitTask($process, $pipes, $stdoutFile, fn () => 'second', 'task-reuse-2');
        $completed = array_values(array_filter($frames2, fn ($f) => ($f['status'] ?? '') === 'COMPLETED'));

        expect($completed)->not->toBeEmpty('Second task did not complete');
        expect($completed[0]['result'])->toBe('second');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('handles complex serialized return values', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, fn () => new DateTime('2025-06-01'), 'task-object');
        $completed = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'COMPLETED'));

        expect($completed)->not->toBeEmpty('No COMPLETED frame received');
        expect($completed[0]['result_serialized'])->toBeTrue();

        $obj = unserialize(base64_decode($completed[0]['result']));
        expect($obj)->toBeInstanceOf(DateTime::class);
        expect($obj->format('Y-m-d'))->toBe('2025-06-01');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('sends CRASHED frame when worker calls exit()', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, function () {
            exit(1);
        }, 'task-crash');
        $errorFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'ERROR'));
        $crashedFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'CRASHED'));

        expect($errorFrames)->not->toBeEmpty('No ERROR frame sent before CRASHED');
        expect($crashedFrames)->not->toBeEmpty('No CRASHED frame received after exit(1)');
        expect($errorFrames[0]['message'])->toContain('crashed unexpectedly');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('sends ERROR frame for catchable fatal (calling undefined function)', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, function () {
            /** @phpstan-ignore-next-line */
            \Hibla\Parallel\Tests\nonExistentFunction();
        }, 'task-caught-error');
        $errorFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'ERROR'));

        expect($errorFrames)->not->toBeEmpty('No ERROR frame received for undefined function call');
        expect($errorFrames[0]['message'])->toContain('nonExistentFunction');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('sends CRASHED frame on true uncatchable fatal (OOM)', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $frames = $submitTask($process, $pipes, $stdoutFile, function () {
            ini_set('memory_limit', '10M');
            $leak = str_repeat('a', 1024 * 1024 * 20);
        }, 'task-oom');

        $errorFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'ERROR'));
        $crashedFrames = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'CRASHED'));

        expect($errorFrames)->not->toBeEmpty('No ERROR frame sent before CRASHED');
        expect($crashedFrames)->not->toBeEmpty('No CRASHED frame received after OOM fatal');

        $teardown($process, $pipes, $stdoutFile);
    });

    it('runs multiple sequential tasks on the same worker without state leaking', function () use ($bootWorker, $submitTask, $teardown) {
        [$process, $pipes, $stdoutFile] = $bootWorker();

        $results = [];
        for ($i = 1; $i <= 4; $i++) {
            $n = $i;
            $frames = $submitTask($process, $pipes, $stdoutFile, function () use ($n) {
                static $count = 0;
                $count++;

                return "task-{$n} count={$count}";
            }, "task-state-{$i}");

            $completed = array_values(array_filter($frames, fn ($f) => ($f['status'] ?? '') === 'COMPLETED'));
            expect($completed)->not->toBeEmpty("Task {$i} did not complete");
            $results[] = $completed[0]['result'];
        }

        foreach ($results as $result) {
            expect($result)->toContain('count=1');
        }

        $teardown($process, $pipes, $stdoutFile);
    });
});
