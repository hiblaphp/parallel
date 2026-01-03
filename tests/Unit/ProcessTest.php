<?php

declare(strict_types=1);

use function Hibla\await;

use Hibla\Parallel\Process;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;

use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

describe('Process', function () {
    $tempFiles = [];

    afterEach(function () use (&$tempFiles) {
        Mockery::close();

        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $tempFiles = [];
    });

    $createProcess = function (array $outputLines, array $finalStatusPayload = []) use (&$tempFiles) {
        $taskId = 'test_task';
        $pid = 1234;
        $statusFile = null;

        if (PHP_OS_FAMILY === 'Windows') {
            $statusFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_unit_test_' . uniqid() . '.json';
            file_put_contents($statusFile, json_encode($finalStatusPayload));
            $tempFiles[] = $statusFile;
        }

        $stdin = Mockery::mock(PromiseWritableStreamInterface::class);
        $stdin->shouldReceive('close')->andReturnNull();

        $stdout = Mockery::mock(PromiseReadableStreamInterface::class);
        $returnValues = [];
        foreach ($outputLines as $line) {
            $returnValues[] = Promise::resolved($line);
        }
        $returnValues[] = Promise::resolved(null);
        $stdout->shouldReceive('readLineAsync')->andReturn(...$returnValues);
        $stdout->shouldReceive('close')->andReturnNull();

        $stderr = Mockery::mock(PromiseReadableStreamInterface::class);
        $stderr->shouldReceive('close')->andReturnNull();

        return new Process($taskId, $pid, null, $stdin, $stdout, $stderr, $statusFile ?? '/tmp/dummy.json', false);
    };

    it('parses a successful result from the stream', function () use ($createProcess) {
        $finalPayload = ['status' => 'COMPLETED', 'result' => 'Success Data', 'result_serialized' => false];
        $lines = [json_encode($finalPayload)];

        $process = $createProcess($lines, $finalPayload);
        $result = \Hibla\await($process->getResult());

        expect($result)->toBe('Success Data');
    });

    it('reconstructs an exception from worker error', function () use ($createProcess) {
        $finalPayload = [
            'status' => 'ERROR',
            'class' => 'InvalidArgumentException',
            'message' => 'Invalid data provided',
            'code' => 400,
            'file' => '/app/test.php',
            'line' => 10,
        ];
        $lines = [json_encode($finalPayload)];

        $process = $createProcess($lines, $finalPayload);
        expect(fn () => \Hibla\await($process->getResult()))
            ->toThrow(InvalidArgumentException::class, 'Invalid data provided')
        ;
    });

    it('captures output before completion', function () use ($createProcess) {
        $finalPayload = ['status' => 'COMPLETED', 'result' => 'Done', 'result_serialized' => false, 'buffered_output' => 'Processing...'];

        $lines = [
            json_encode(['status' => 'OUTPUT', 'output' => 'Processing...']),
            json_encode($finalPayload),
        ];

        $process = $createProcess($lines, $finalPayload);

        ob_start();
        $result = await($process->getResult());
        $output = ob_get_clean();

        expect($result)->toBe('Done');
        expect($output)->toBe('Processing...');
    });

    it('handles serialized results (objects)', function () use ($createProcess) {
        $date = new DateTime('2024-01-01');
        $serialized = base64_encode(serialize($date));
        $finalPayload = ['status' => 'COMPLETED', 'result' => $serialized, 'result_serialized' => true];
        $lines = [json_encode($finalPayload)];

        $process = $createProcess($lines, $finalPayload);
        $result = await($process->getResult());

        expect($result)->toBeInstanceOf(DateTime::class);
        expect($result->format('Y-m-d'))->toBe('2024-01-01');
    });
});
