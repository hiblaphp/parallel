<?php

use Hibla\Parallel\Process;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use Hibla\Promise\Promise;

describe('Process', function () {
    afterEach(function () {
        Mockery::close();
    });

    $createProcess = function (array $outputLines) {
        $taskId = 'test_task';
        $pid = 1234;
        $statusFile = '/tmp/test.json';

        // Mock Write Stream (Stdin)
        $stdin = Mockery::mock(PromiseWritableStreamInterface::class);
        $stdin->shouldReceive('close')->andReturnNull();

        // Mock Read Stream (Stdout)
        $stdout = Mockery::mock(PromiseReadableStreamInterface::class);
        
        // Prepare the sequence of return values (Promises)
        $returnValues = [];
        foreach ($outputLines as $line) {
            $returnValues[] = Promise::resolved($line);
        }
        // Append null to signal End-Of-Stream
        $returnValues[] = Promise::resolved(null);
        
        // Pass ALL return values at once to create a sequence
        $stdout->shouldReceive('readLineAsync')
            ->andReturn(...$returnValues);
        
        $stdout->shouldReceive('close')->andReturnNull();

        // Mock Stderr
        $stderr = Mockery::mock(PromiseReadableStreamInterface::class);
        $stderr->shouldReceive('close')->andReturnNull();

        // Pass null as processResource since we are mocking streams directly
        return new Process($taskId, $pid, null, $stdin, $stdout, $stderr, $statusFile, false);
    };

    it('parses a successful result from the stream', function () use ($createProcess) {
        $lines = [
            json_encode(['status' => 'COMPLETED', 'result' => 'Success Data', 'result_serialized' => false])
        ];

        $process = $createProcess($lines);

        $result = \Hibla\await($process->getResult());

        expect($result)->toBe('Success Data');
    });

    it('reconstructs an exception from worker error', function () use ($createProcess) {
        $lines = [
            json_encode([
                'status' => 'ERROR', 
                'class' => 'InvalidArgumentException',
                'message' => 'Invalid data provided',
                'code' => 400,
                'file' => '/app/test.php',
                'line' => 10
            ])
        ];

        $process = $createProcess($lines);

        expect(fn() => \Hibla\await($process->getResult()))
            ->toThrow(InvalidArgumentException::class, 'Invalid data provided');
    });

    it('captures output before completion', function () use ($createProcess) {
        $lines = [
            json_encode(['status' => 'OUTPUT', 'output' => 'Processing...']),
            json_encode(['status' => 'COMPLETED', 'result' => 'Done', 'result_serialized' => false])
        ];

        $process = $createProcess($lines);
        
        ob_start();
        $result = \Hibla\await($process->getResult());
        $output = ob_get_clean();

        expect($result)->toBe('Done');
        expect($output)->toBe('Processing...');
    });
    
    it('handles serialized results (objects)', function () use ($createProcess) {
        $date = new DateTime('2024-01-01');
        $serialized = base64_encode(serialize($date));

        $lines = [
            json_encode(['status' => 'COMPLETED', 'result' => $serialized, 'result_serialized' => true])
        ];

        $process = $createProcess($lines);
        $result = \Hibla\await($process->getResult());

        expect($result)->toBeInstanceOf(DateTime::class);
        expect($result->format('Y-m-d'))->toBe('2024-01-01');
    });
});