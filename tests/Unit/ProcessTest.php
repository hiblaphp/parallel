<?php

declare(strict_types=1);

use Hibla\Parallel\Internals\Process;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

use function Hibla\await;

describe('Process', function () {
    afterEach(function () {
        Mockery::close();
    });

    $createProcess = function (array $outputLines) {
        $pid = 1234;

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

        return new Process($pid, null, $stdin, $stdout, $stderr);
    };

    it('parses a successful result from the stream', function () use ($createProcess) {
        $lines = [json_encode(['status' => 'COMPLETED', 'result' => 'Success Data', 'result_serialized' => false])];

        $process = $createProcess($lines);
        $result = await($process->getResult());

        expect($result)->toBe('Success Data');
    });

    it('reconstructs an exception from worker error', function () use ($createProcess) {
        $lines = [json_encode([
            'status' => 'ERROR',
            'class' => 'InvalidArgumentException',
            'message' => 'Invalid data provided',
            'code' => 400,
            'file' => '/app/test.php',
            'line' => 10,
        ])];

        $process = $createProcess($lines);
        expect(fn () => await($process->getResult()))
            ->toThrow(InvalidArgumentException::class, 'Invalid data provided')
        ;
    });

    it('captures output before completion', function () use ($createProcess) {
        $lines = [
            json_encode(['status' => 'OUTPUT', 'output' => 'Processing...']),
            json_encode(['status' => 'COMPLETED', 'result' => 'Done', 'result_serialized' => false]),
        ];

        $process = $createProcess($lines);

        ob_start();
        $result = await($process->getResult());
        $output = ob_get_clean();

        expect($result)->toBe('Done');
        expect($output)->toBe('Processing...');
    });

    it('handles serialized results (objects)', function () use ($createProcess) {
        $date = new DateTime('2024-01-01');
        $lines = [json_encode([
            'status' => 'COMPLETED',
            'result' => base64_encode(serialize($date)),
            'result_serialized' => true,
        ])];

        $process = $createProcess($lines);
        $result = await($process->getResult());

        expect($result)->toBeInstanceOf(DateTime::class);
        expect($result->format('Y-m-d'))->toBe('2024-01-01');
    });
});
