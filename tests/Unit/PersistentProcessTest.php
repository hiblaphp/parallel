<?php

declare(strict_types=1);

use function Hibla\await;
use function Hibla\delay;

use Hibla\Parallel\Exceptions\ProcessCrashedException;
use Hibla\Parallel\Internals\PersistentProcess;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;

/**
 * Builds a PersistentProcess whose stdout emits the given lines in order,
 * followed by a null sentinel to close the stream.
 *
 * @param list<string> $outputLines
 */
function createPersistentProcess(array $outputLines, int $pid = 1234): PersistentProcess
{
    $stdin = Mockery::mock(PromiseWritableStreamInterface::class);
    $stdin->shouldReceive('writeAsync')->andReturn(Promise::resolved(null));
    $stdin->shouldReceive('close')->andReturnNull();

    $stdout = Mockery::mock(PromiseReadableStreamInterface::class);
    $returns = array_map(fn($line) => Promise::resolved($line), $outputLines);
    $returns[] = Promise::resolved(null); 
    $stdout->shouldReceive('readLineAsync')->andReturn(...$returns);
    $stdout->shouldReceive('close')->andReturnNull();

    $stderr = Mockery::mock(PromiseReadableStreamInterface::class);
    $stderr->shouldReceive('close')->andReturnNull();

    return new PersistentProcess($pid, null, $stdin, $stdout, $stderr);
}

/**
 * Boots the read loop with no-op callbacks, submits a task, and returns
 * the task promise. The caller should await() or chain the promise.
 */
function submitPersistentTask(PersistentProcess $process, string $taskId = 'task-abc'): PromiseInterface
{
    $process->startReadLoop(fn() => null, fn() => null);

    return $process->submitTask($taskId, json_encode(['task_id' => $taskId]));
}

describe('PersistentProcess', function () {
    afterEach(function () {
        Mockery::close();
    });
    it('fires onReadyCallback when a READY frame arrives', function () {
        $readyFired  = false;
        $reportedPid = null;

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 5678]),
        ];

        $process = createPersistentProcess($lines, 1234);
        $process->startReadLoop(
            function (PersistentProcess $p) use (&$readyFired, &$reportedPid) {
                $readyFired  = true;
                $reportedPid = $p->getWorkerPid();
            },
            fn() => null
        );

        await(delay(0.05));

        expect($readyFired)->toBeTrue();
        expect($reportedPid)->toBe(5678);
    });

    it('stores the real worker PID from the READY frame', function () {
        $lines = [json_encode(['status' => 'READY', 'pid' => 9999])];

        $process = createPersistentProcess($lines, 1234);
        $process->startReadLoop(fn() => null, fn() => null);

        await(delay(0.05));

        expect($process->getWorkerPid())->toBe(9999);
    });

    it('falls back to the proc_get_status PID before a READY frame arrives', function () {
        $process = createPersistentProcess([]);

        expect($process->getWorkerPid())->toBe(1234);
        expect($process->getPid())->toBe(1234);
    });

    it('marks the worker as not busy after a READY frame', function () {
        $lines = [json_encode(['status' => 'READY', 'pid' => 1234])];

        $process = createPersistentProcess($lines);
        expect($process->isBusy())->toBeTrue();

        $process->startReadLoop(fn() => null, fn() => null);
        await(delay(0.05));

        expect($process->isBusy())->toBeFalse();
    });

    it('resolves the task promise with a scalar result', function () {
        $taskId = 'task-1';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 'hello', 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBe('hello');
    });

    it('resolves the task promise with an integer result', function () {
        $taskId = 'task-int';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 42, 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBe(42);
    });

    it('resolves the task promise with null', function () {
        $taskId = 'task-null';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBeNull();
    });

    it('deserializes a serialized object result', function () {
        $taskId = 'task-obj';
        $date   = new DateTime('2024-06-01');
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode([
                'status'            => 'COMPLETED',
                'task_id'           => $taskId,
                'result'            => base64_encode(serialize($date)),
                'result_serialized' => true,
            ]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBeInstanceOf(DateTime::class);
        expect($result->format('Y-m-d'))->toBe('2024-06-01');
    });

    it('rejects the task promise with the teleported exception class', function () {
        $taskId = 'task-err';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode([
                'status'      => 'ERROR',
                'task_id'     => $taskId,
                'class'       => 'InvalidArgumentException',
                'message'     => 'Bad input',
                'code'        => 0,
                'file'        => '/worker.php',
                'line'        => 10,
                'stack_trace' => '#0 ...',
            ]),
        ];

        expect(fn() => await(submitPersistentTask(createPersistentProcess($lines), $taskId)))
            ->toThrow(InvalidArgumentException::class, 'Bad input');
    });

    it('rejects with RuntimeException when the exception class is unknown', function () {
        $taskId = 'task-unknown-err';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode([
                'status'      => 'ERROR',
                'task_id'     => $taskId,
                'class'       => 'App\\Exceptions\\SomeObscureException',
                'message'     => 'Unknown error occurred',
                'code'        => 0,
                'file'        => '/worker.php',
                'line'        => 20,
                'stack_trace' => '#0 ...',
            ]),
        ];

        expect(fn() => await(submitPersistentTask(createPersistentProcess($lines), $taskId)))
            ->toThrow(\RuntimeException::class, 'Unknown error occurred');
    });

    // =========================================================================
    // OUTPUT frame
    // =========================================================================

    it('echoes OUTPUT frames to the console', function () {
        $taskId = 'task-output';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'OUTPUT', 'task_id' => $taskId, 'output' => 'Hello from worker']),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        ob_start();
        await(submitPersistentTask(createPersistentProcess($lines), $taskId));
        $output = ob_get_clean();

        expect($output)->toBe('Hello from worker');
    });

    it('echoes multiple OUTPUT frames in order', function () {
        $taskId = 'task-multi-output';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'OUTPUT', 'task_id' => $taskId, 'output' => 'line 1']),
            json_encode(['status' => 'OUTPUT', 'task_id' => $taskId, 'output' => 'line 2']),
            json_encode(['status' => 'OUTPUT', 'task_id' => $taskId, 'output' => 'line 3']),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        ob_start();
        await(submitPersistentTask(createPersistentProcess($lines), $taskId));
        $output = ob_get_clean();

        expect($output)->toBe('line 1line 2line 3');
    });

    // =========================================================================
    // MESSAGE frame
    // =========================================================================

    it('fires the onMessage handler when a MESSAGE frame arrives', function () {
        $taskId   = 'task-msg';
        $received = [];

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'MESSAGE', 'task_id' => $taskId, 'pid' => 1234, 'data' => 'ping', 'data_serialized' => false]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        $promise = $process->submitTask(
            $taskId,
            json_encode(['task_id' => $taskId]),
            'unknown',
            function (WorkerMessage $msg) use (&$received) {
                $received[] = $msg->data;
            }
        );

        await($promise);

        expect($received)->toBe(['ping']);
    });

    it('deserializes serialized MESSAGE data', function () {
        $taskId   = 'task-ser-msg';
        $date     = new DateTime('2025-01-01');
        $received = [];

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode([
                'status'          => 'MESSAGE',
                'task_id'         => $taskId,
                'pid'             => 1234,
                'data'            => base64_encode(serialize($date)),
                'data_serialized' => true,
            ]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        $promise = $process->submitTask(
            $taskId,
            json_encode(['task_id' => $taskId]),
            'unknown',
            function (WorkerMessage $msg) use (&$received) {
                $received[] = $msg->data;
            }
        );

        await($promise);

        expect($received[0])->toBeInstanceOf(DateTime::class);
        expect($received[0]->format('Y-m-d'))->toBe('2025-01-01');
    });

    it('WorkerMessage carries the correct pid from the MESSAGE frame', function () {
        $taskId  = 'task-pid-check';
        $msgPids = [];

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'MESSAGE', 'task_id' => $taskId, 'pid' => 7777, 'data' => 'x', 'data_serialized' => false]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => null, 'result_serialized' => false]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        $promise = $process->submitTask(
            $taskId,
            json_encode(['task_id' => $taskId]),
            'unknown',
            function (WorkerMessage $msg) use (&$msgPids) {
                $msgPids[] = $msg->pid;
            }
        );

        await($promise);

        expect($msgPids)->toBe([7777]);
    });

    it('task promise resolves only after all MESSAGE handlers have finished', function () {
        $taskId = 'task-handler-order';
        $log    = [];

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'MESSAGE', 'task_id' => $taskId, 'pid' => 1234, 'data' => 'go', 'data_serialized' => false]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 'done', 'result_serialized' => false]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        $promise = $process->submitTask(
            $taskId,
            json_encode(['task_id' => $taskId]),
            'unknown',
            function (WorkerMessage $msg) use (&$log) {
                await(delay(0.05));
                $log[] = 'handler-done';
            }
        );

        $result = await($promise);
        $log[]  = 'promise-resolved';

        expect($log)->toBe(['handler-done', 'promise-resolved']);
        expect($result)->toBe('done');
    });

    it('fires onCrashCallback when a CRASHED frame arrives', function () {
        $crashFired = false;

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'CRASHED']),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(
            fn() => null,
            function (PersistentProcess $p) use (&$crashFired) {
                $crashFired = true;
            }
        );

        await(delay(0.05));

        expect($crashFired)->toBeTrue();
    });

    it('rejects pending task promises with ProcessCrashedException on CRASHED frame', function () {
        $taskId = 'task-crash';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode([
                'status'      => 'ERROR',
                'task_id'     => $taskId,
                'class'       => ProcessCrashedException::class,
                'message'     => 'Worker crashed mid-task',
                'code'        => 0,
                'file'        => '/worker.php',
                'line'        => 1,
                'stack_trace' => '',
            ]),
            json_encode(['status' => 'CRASHED']),
        ];

        expect(fn() => await(submitPersistentTask(createPersistentProcess($lines), $taskId)))
            ->toThrow(ProcessCrashedException::class);
    });

    it('marks the worker as dead after a CRASHED frame', function () {
        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'CRASHED']),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        await(delay(0.05));

        expect($process->isAlive())->toBeFalse();
    });

    it('fires onCrashCallback when a RETIRING frame arrives', function () {
        $retireFired = false;

        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'RETIRING', 'executions' => 10]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(
            fn() => null,
            function (PersistentProcess $p) use (&$retireFired) {
                $retireFired = true;
            }
        );

        await(delay(0.05));

        expect($retireFired)->toBeTrue();
    });

    it('marks the worker as dead after a RETIRING frame', function () {
        $lines = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'RETIRING', 'executions' => 5]),
        ];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        await(delay(0.05));

        expect($process->isAlive())->toBeFalse();
    });

    it('fires onCrashCallback when the stdout stream closes unexpectedly', function () {
        $crashFired = false;

        $stdin = Mockery::mock(PromiseWritableStreamInterface::class);
        $stdin->shouldReceive('writeAsync')->andReturn(Promise::resolved(null));
        $stdin->shouldReceive('close')->andReturnNull();

        $stdout = Mockery::mock(PromiseReadableStreamInterface::class);
        $stdout->shouldReceive('readLineAsync')
            ->andThrow(new \RuntimeException('Stream closed unexpectedly'));
        $stdout->shouldReceive('close')->andReturnNull();

        $stderr = Mockery::mock(PromiseReadableStreamInterface::class);
        $stderr->shouldReceive('close')->andReturnNull();

        $process = new PersistentProcess(1234, null, $stdin, $stdout, $stderr);
        $process->startReadLoop(
            fn() => null,
            function (PersistentProcess $p) use (&$crashFired) {
                $crashFired = true;
            }
        );

        await(delay(0.05));

        expect($crashFired)->toBeTrue();
    });

    it('marks the worker as dead when the stdout stream closes with a clean EOF', function () {
        $process = createPersistentProcess([]);
        $process->startReadLoop(fn() => null, fn() => null);

        await(delay(0.05));

        expect($process->isAlive())->toBeFalse();
    });

    it('starts in a busy state before any READY frame', function () {
        $process = createPersistentProcess([]);

        expect($process->isBusy())->toBeTrue();
        expect($process->isAlive())->toBeTrue();
    });

    it('is not busy after terminate() is called', function () {
        $lines = [json_encode(['status' => 'READY', 'pid' => 1234])];

        $process = createPersistentProcess($lines);
        $process->startReadLoop(fn() => null, fn() => null);

        await(delay(0.05));
        expect($process->isBusy())->toBeFalse();

        $process->terminate();

        expect($process->isAlive())->toBeFalse();
        expect($process->isBusy())->toBeFalse();
    });

    it('silently ignores blank lines in the stream', function () {
        $taskId = 'task-blanks';
        $lines  = [
            '',
            '   ',
            json_encode(['status' => 'READY', 'pid' => 1234]),
            '',
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 'ok', 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBe('ok');
    });

    it('silently ignores malformed JSON lines', function () {
        $taskId = 'task-malformed';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            'this is not json {{{',
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 'ok', 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBe('ok');
    });

    it('silently ignores frames with an unknown task_id', function () {
        $taskId = 'task-known';
        $lines  = [
            json_encode(['status' => 'READY', 'pid' => 1234]),
            json_encode(['status' => 'COMPLETED', 'task_id' => 'unknown-id', 'result' => 'ignored', 'result_serialized' => false]),
            json_encode(['status' => 'COMPLETED', 'task_id' => $taskId, 'result' => 'correct', 'result_serialized' => false]),
        ];

        $result = await(submitPersistentTask(createPersistentProcess($lines), $taskId));

        expect($result)->toBe('correct');
    });
});
