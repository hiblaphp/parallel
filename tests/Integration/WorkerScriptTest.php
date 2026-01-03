<?php

use Rcalicdan\Serializer\CallbackSerializationManager;
use Hibla\Stream\PromiseWritableStream;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

describe('Worker Scripts Integration', function () {

    $projectRoot = dirname(__DIR__, 2);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    $streamWorker = $projectRoot . '/src/worker.php';
    $bgWorker = $projectRoot . '/src/worker_background.php';
    $serializer = new CallbackSerializationManager();

    $runStreamWorker = function (callable $task) use ($streamWorker, $autoloadPath, $serializer) {
        return await(async(function() use ($task, $streamWorker, $autoloadPath, $serializer) {
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

            $process = proc_open('php ' . escapeshellarg($streamWorker), $descriptors, $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException("Failed to spawn worker");
            }

            $stdin = new PromiseWritableStream($pipes[0]);
            $stdout = new PromiseReadableStream($pipes[1]);
            $stderr = new PromiseReadableStream($pipes[2]);

            await($stdin->writeAsync($payload . PHP_EOL));
            $stdin->close();

            [$output, $errors] = await(Promise::all([
                $stdout->readAllAsync(),
                $stderr->readAllAsync(),
            ]));
            
            proc_close($process);

            if ($errors) {
                 throw new RuntimeException("Worker Error: " . $errors);
            }

            return array_filter(explode("\n", $output));
        }));
    };

    $runBgWorker = function (callable $task, string $statusFile) use ($bgWorker, $autoloadPath, $serializer) {
        await(async(function() use ($task, $statusFile, $bgWorker, $autoloadPath, $serializer) {
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

            $process = proc_open('php ' . escapeshellarg($bgWorker), $descriptors, $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException("Failed to spawn background worker");
            }

            $stdin = new PromiseWritableStream($pipes[0]);
            $stderr = new PromiseReadableStream($pipes[2]);

            await($stdin->writeAsync($payload . PHP_EOL));
            $stdin->close();
            
            $errors = await($stderr->readAllAsync());
            
            proc_close($process);

            if ($errors) {
                throw new RuntimeException("Background Worker Error: " . $errors);
            }
        }));
    };


    it('executes a closure and streams result back (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(fn() => 'Hello from Stream Worker');
        
        $lastLine = array_pop($lines);
        $data = json_decode($lastLine, true);

        expect($data['status'])->toBe('COMPLETED');
        expect($data['result'])->toBe('Hello from Stream Worker');
    });

    it('captures echoed output as OUTPUT events (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(function() {
            echo "Step 1";
            echo "Step 2";
            return true;
        });

        $foundOutput = false;
        $completed = false;

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (!$data) continue;

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
        $lines = $runStreamWorker(function() {
            throw new InvalidArgumentException("Something went wrong");
        });

        $lastLine = array_pop($lines);
        $data = json_decode($lastLine, true);

        expect($data['status'])->toBe('ERROR');
        expect($data['class'])->toBe(InvalidArgumentException::class);
        expect($data['message'])->toBe("Something went wrong");
    });

    it('handles complex serialized objects (worker.php)', function () use ($runStreamWorker) {
        $lines = $runStreamWorker(function() {
            return new DateTime('2025-01-01');
        });

        $lastLine = array_pop($lines);
        $data = json_decode($lastLine, true);

        expect($data['status'])->toBe('COMPLETED');
        expect($data['result_serialized'])->toBeTrue();

        $resultObj = unserialize(base64_decode($data['result']));
        expect($resultObj)->toBeInstanceOf(DateTime::class);
        expect($resultObj->format('Y-m-d'))->toBe('2025-01-01');
    });

    it('executes closure and updates status file (worker_background.php)', function () use ($runBgWorker) {
        $statusFile = sys_get_temp_dir() . '/hibla_bg_test_' . uniqid() . '.json';
        
        if (file_exists($statusFile)) unlink($statusFile);

        $runBgWorker(fn() => 'Bg Result', $statusFile);
        
        expect(file_exists($statusFile))->toBeTrue();
        
        $content = file_get_contents($statusFile);
        $data = json_decode($content, true);

        expect($data['status'])->toBe('COMPLETED');
        expect($data['message'])->toBe('Task completed');
        
        unlink($statusFile);
    });

    it('catches exceptions and updates status file with error (worker_background.php)', function () use ($runBgWorker) {
        $statusFile = sys_get_temp_dir() . '/hibla_bg_error_' . uniqid() . '.json';
        
        $runBgWorker(function() {
            throw new RuntimeException("Background Crash");
        }, $statusFile);

        expect(file_exists($statusFile))->toBeTrue();
        
        $content = file_get_contents($statusFile);
        $data = json_decode($content, true);

        expect($data['status'])->toBe('ERROR');
        expect($data['message'])->toBe('Background Crash');

        unlink($statusFile);
    });
});