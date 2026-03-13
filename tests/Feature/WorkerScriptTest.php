<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\Serializer\CallbackSerializationManager;

describe('Worker Scripts Integration', function () {
    $projectRoot = dirname(__DIR__, 2);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    $streamWorker = $projectRoot . '/src/worker.php';
    $bgWorker = $projectRoot . '/src/worker_background.php';
    $serializer = new CallbackSerializationManager();
    $utils = new SystemUtilities();

    $runStreamWorker = function (callable $task) use ($streamWorker, $autoloadPath, $serializer, $utils) {
        $serializedCallback = $serializer->serializeCallback($task);

        $payload = json_encode([
            'serialized_callback' => $serializedCallback,
            'autoload_path' => $autoloadPath,
            'timeout_seconds' => 5,
            'memory_limit' => '128M',
        ]);

        $stdoutFile = sys_get_temp_dir() . '/hibla_stream_stdout_' . uniqid() . '.log';
        $stderrFile = sys_get_temp_dir() . '/hibla_stream_stderr_' . uniqid() . '.log';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $phpBinary = $utils->getPhpBinary();
        $process = proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($streamWorker), $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn worker');
        }

        fwrite($pipes[0], $payload . PHP_EOL);
        fflush($pipes[0]);
        fclose($pipes[0]);

        $start = microtime(true);
        $timedOut = false;

        do {
            if (microtime(true) - $start > 10) {
                $timedOut = true;

                break;
            }
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running']);

        $pid = proc_get_status($process)['pid'];
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID {$pid} 2>nul");
        } else {
            exec("pkill -9 -P {$pid} 2>/dev/null; kill -9 {$pid} 2>/dev/null");
        }

        if ($timedOut) {
            throw new RuntimeException('Worker test timed out after 10 seconds');
        }

        proc_close($process);

        $output = file_get_contents($stdoutFile);
        $errors = file_get_contents($stderrFile);

        @unlink($stdoutFile);
        @unlink($stderrFile);

        if ($errors) {
            throw new RuntimeException('Worker Error: ' . $errors);
        }

        $output = str_replace("\r\n", "\n", (string)$output);

        return array_filter(explode("\n", $output));
    };

    $runBgWorker = function (callable $task) use ($bgWorker, $autoloadPath, $serializer, $utils) {
        $serializedCallback = $serializer->serializeCallback($task);

        $payload = json_encode([
            'serialized_callback' => $serializedCallback,
            'autoload_path' => $autoloadPath,
            'timeout_seconds' => 5,
            'memory_limit' => '128M',
        ]);

        $stderrFile = sys_get_temp_dir() . '/hibla_bg_stderr_' . uniqid() . '.log';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        $phpBinary = $utils->getPhpBinary();
        $process = proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($bgWorker), $descriptors, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn background worker');
        }

        fwrite($pipes[0], $payload . PHP_EOL);
        fflush($pipes[0]);
        fclose($pipes[0]);

        $start = microtime(true);
        $timedOut = false;

        do {
            if (microtime(true) - $start > 10) {
                $timedOut = true;

                break;
            }
            usleep(10000);
            $status = proc_get_status($process);
        } while ($status['running']);

        // Forcefully kill the process (and any spawned children) after each test execution
        $pid = proc_get_status($process)['pid'];
        if (PHP_OS_FAMILY === 'Windows') {
            exec("taskkill /F /T /PID {$pid} 2>nul");
        } else {
            exec("pkill -9 -P {$pid} 2>/dev/null; kill -9 {$pid} 2>/dev/null");
        }

        if ($timedOut) {
            throw new RuntimeException('Background worker test timed out after 10 seconds');
        }

        proc_close($process);

        $exitCode = proc_get_status($process)['exitcode'] ?? -1;

        $errors = file_get_contents($stderrFile);
        @unlink($stderrFile);

        if ($errors) {
            throw new RuntimeException('Background Worker Error: ' . $errors);
        }
    };

    it('executes a closure and streams result back (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(fn () => 'Hello from Stream Worker');

        $lastLine = array_pop($lines);
        $data = json_decode((string)$lastLine, true);

        expect($data)->not->toBeNull('JSON decode failed. Last line was: ' . var_export($lastLine, true));
        expect($data['status'])->toBe('COMPLETED');
        expect($data['result'])->toBe('Hello from Stream Worker');
    });

    it('captures echoed output as OUTPUT events (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(function () {
            echo 'Step 1';
            echo 'Step 2';

            return true;
        });

        $foundOutput = false;
        $completed = false;

        foreach ($lines as $line) {
            $data = json_decode((string)$line, true);
            if (! $data) {
                continue;
            }

            if (($data['status'] ?? '') === 'OUTPUT') {
                expect($data['output'])->toContain('Step');
                $foundOutput = true;
            }
            if (($data['status'] ?? '') === 'COMPLETED') {
                $completed = true;
            }
        }

        expect($foundOutput)->toBeTrue();
        expect($completed)->toBeTrue();
    });

    it('catches exceptions and returns ERROR status (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(function () {
            throw new InvalidArgumentException('Something went wrong');
        });

        $lastLine = array_pop($lines);
        $data = json_decode((string)$lastLine, true);

        expect($data)->not->toBeNull();
        expect($data['status'])->toBe('ERROR');
        expect($data['class'])->toBe(InvalidArgumentException::class);
        expect($data['message'])->toBe('Something went wrong');
    });

    it('handles complex serialized objects (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(function () {
            return new DateTime('2025-01-01');
        });

        $lastLine = array_pop($lines);
        $data = json_decode((string)$lastLine, true);

        expect($data)->not->toBeNull();
        expect($data['status'])->toBe('COMPLETED');
        expect($data['result_serialized'])->toBeTrue();

        $resultObj = unserialize(base64_decode($data['result']));
        expect($resultObj)->toBeInstanceOf(DateTime::class);
        expect($resultObj->format('Y-m-d'))->toBe('2025-01-01');
    });

    it('executes a closure without errors (worker_background.php)', function () use ($runBgWorker) {
        expect(fn () => $runBgWorker(fn () => true))->not->toThrow(RuntimeException::class);
    });

    it('handles exceptions silently without crashing (worker_background.php)', function () use ($runBgWorker) {
        expect(fn () => $runBgWorker(function () {
            throw new RuntimeException('Background Crash');
        }))->not->toThrow(RuntimeException::class);
    });
});
