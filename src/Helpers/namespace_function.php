<?php 

namespace Hibla;

use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;

/**
 * Run a task in a true, non-blocking background process.
 *
 * This function is the simplest way to offload a CPU-intensive task to a separate
 * process without blocking the main event loop. It establishes a low-latency,
 * stream-based communication channel for real-time results, powered by `proc_open`
 * and the Hibla async ecosystem.
 *
 * (The rest of the docblock and examples remain the same, as the external API is unchanged)
 *
 * @param  callable  $task  The task to execute in parallel.
 * @param  array     $context  Optional context to pass to the task.
 * @param  int       $timeout  Maximum seconds to wait for the task to complete.
 * @return PromiseInterface Promise resolving to the task's return value.
 */
function parallel(callable $task, array $context = [], int $timeout = 60): PromiseInterface
{
    return async(function () use ($task, $context, $timeout) {
        $process = await(Process::spawn($task, $context));
        
        return await($process->await($timeout));
    });
}