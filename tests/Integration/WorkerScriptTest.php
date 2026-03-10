<?php

declare(strict_types=1);

use function Hibla\async;
use function Hibla\await;

use Hibla\Parallel\Utilities\SystemUtilities;
use Hibla\Promise\Promise;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;
use Rcalicdan\Serializer\CallbackSerializationManager;

describe('Worker Scripts Integration', function () {
    $projectRoot = dirname(__DIR__, 2);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    $streamWorker = $projectRoot . '/src/worker.php';
    $bgWorker = $projectRoot . '/src/worker_background.php';
    $serializer = new CallbackSerializationManager();
    $utils = new SystemUtilities();

    $runStreamWorker = function (callable $task) use ($streamWorker, $autoloadPath, $serializer, $utils) {
        return await(async(function () use ($task, $streamWorker, $autoloadPath, $serializer, $utils) {
            $serializedCallback = $serializer->serializeCallback($task);

            $payload = json_encode([
                'task_id' => 'test_stream_worker',
                'status_file' => null,
                'serialized_callback' => $serializedCallback,
                'autoload_path' => $autoloadPath,
                'logging_enabled' => false,
                'timeout_seconds' => 5,
                'memory_limit' => '128M',
            ]);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $phpBinary = $utils->getPhpBinary();
            $process = proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($streamWorker), $descriptors, $pipes);

            if (! is_resource($process)) {
                throw new RuntimeException('Failed to spawn worker');
            }

            stream_set_blocking($pipes[0], false);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdin = new PromiseWritableStream($pipes[0]);
            $stdout = new PromiseReadableStream($pipes[1]);
            $stderr = new PromiseReadableStream($pipes[2]);

            await($stdin->writeAsync($payload . PHP_EOL));

            [$output, $errors] = await(Promise::all([
                $stdout->readAllAsync(),
                $stderr->readAllAsync(),
            ]));

            $stdin->close();
            proc_close($process);

            if ($errors) {
                throw new RuntimeException('Worker Error: ' . $errors);
            }

            return array_filter(explode("\n", (string)$output));
        }));
    };

    $runBgWorker = function (callable $task, string $statusFile) use ($bgWorker, $autoloadPath, $serializer, $utils) {
        await(async(function () use ($task, $statusFile, $bgWorker, $autoloadPath, $serializer, $utils) {
            $serializedCallback = $serializer->serializeCallback($task);

            $payload = json_encode([
                'task_id' => 'test_bg_worker',
                'status_file' => $statusFile,
                'serialized_callback' => $serializedCallback,
                'autoload_path' => $autoloadPath,
                'logging_enabled' => true,
                'timeout_seconds' => 5,
                'memory_limit' => '128M',
            ]);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $phpBinary = $utils->getPhpBinary();
            $process = proc_open(escapeshellarg($phpBinary) . ' ' . escapeshellarg($bgWorker), $descriptors, $pipes);

            if (! is_resource($process)) {
                throw new RuntimeException('Failed to spawn background worker');
            }

            fwrite($pipes[0], $payload . PHP_EOL);
            fflush($pipes[0]);
            fclose($pipes[0]);

            stream_set_blocking($pipes[2], false);
            $stderr = new PromiseReadableStream($pipes[2]);

            $errors = await($stderr->readAllAsync());

            fclose($pipes[1]);
            proc_close($process);

            if ($errors) {
                throw new RuntimeException('Background Worker Error: ' . $errors);
            }
        }));
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

    it('executes closure and updates status file (worker_background.php)', function () use ($runBgWorker) {
        $statusFile = sys_get_temp_dir() . '/hibla_bg_test_' . uniqid() . '.json';

        if (file_exists($statusFile)) {
            unlink($statusFile);
        }

        $runBgWorker(fn () => 'Bg Result', $statusFile);

        expect(file_exists($statusFile))->toBeTrue();

        $content = file_get_contents($statusFile);
        $data = json_decode($content, true);

        expect($data['status'])->toBe('COMPLETED');
        expect($data['message'])->toBe('Task completed');

        if (file_exists($statusFile)) {
            unlink($statusFile);
        }
    });

    it('catches exceptions and updates status file with error (worker_background.php)', function () use ($runBgWorker) {
        $statusFile = sys_get_temp_dir() . '/hibla_bg_error_' . uniqid() . '.json';

        $runBgWorker(function () {
            throw new RuntimeException('Background Crash');
        }, $statusFile);

        expect(file_exists($statusFile))->toBeTrue();

        $content = file_get_contents($statusFile);
        $data = json_decode($content, true);

        expect($data['status'])->toBe('ERROR');
        expect($data['message'])->toBe('Background Crash');

        if (file_exists($statusFile)) {
            unlink($statusFile);
        }
    });
});
