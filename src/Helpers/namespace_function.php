<?php 

namespace Hibla;

use Hibla\Parallel\Process;
use Hibla\Promise\Interfaces\PromiseInterface;
use function Hibla\async;
use function Hibla\await;

/**
 * Run a task in parallel (separate process) and return a Promise.
 *
 *⚠️ CLOSURE SERIALIZATION WARNING
 * 
 * The way you format closures affects what gets captured during serialization:
 * 
 * ❌ DANGEROUS (may cause fork bomb and the process spawner imidiately throw an exception to prevent mass spawn):
 * async(fn() => await(parallel(fn() => sleep(5))));
 * 
 * ✅ SAFE (multi-line formatting):
 * async(
 *     fn() => await(parallel(
 *         fn() => sleep(5)
 *     ))
 * );
 * 
 * ✅ SAFEST (use regular closures):
 * async(function() {
 *     return await(parallel(function() {
 *         return sleep(5);
 *     }));
 * });
 * 
 * ✅ BEST (avoid nesting entirely):
 * await(parallel(fn() => sleep(5)));
 * 
 * **Platform Behavior:**
 * - On **Linux**: Fire-and-forget calls are non-blocking and run truly in parallel
 * - On **Windows**: Fire-and-forget calls may block due to stream handling limitations
 *   - ✅ **Recommended**: Always use with Promise combinators (`Promise::all()`, `Promise::race()`)
 *   - ✅ **For fire-and-forget**: Use `Process::spawn()` directly (see examples below)
 *   - ❌ **Not recommended**: Calling `parallel()` without awaiting on Windows
 *
 * **Examples:**
 * ```php
 * // ✅ Recommended: Works on all platforms
 * $results = await(Promise::all([
 *     parallel(fn() => task1()),
 *     parallel(fn() => task2()),
 * ]));
 *
 * // ✅ Also works: Using Parallel class
 * $results = await(Parallel::all([
 *     fn() => task1(),
 *     fn() => task2(),
 * ], maxConcurrency: 4));
 *
 * // ✅ Fire-and-forget on Windows: Use Process::spawn()
 * $process = await(Process::spawn(fn() => backgroundTask()));
 * // Process runs in background, no blocking
 * // Optionally await later: $result = await($process->await());
 *
 * // ⚠️ Fire-and-forget with parallel() (may block on Windows)
 * parallel(fn() => heavyTask());
 * // On Windows, this blocks until complete
 * // On Linux, this returns immediately
 * ```
 *
 * **Why the difference?**
 * `parallel()` internally awaits the process result, which may block on Windows.
 * `Process::spawn()` only spawns the process and returns immediately, allowing
 * true fire-and-forget behavior on all platforms.
 *
 * @template TResult
 *
 * @param  callable(array): TResult  $task  The task to execute in parallel
 * @param  array<string, mixed>  $context  Optional context/parameters to pass to the task
 * @param  int  $timeout  Maximum seconds to wait for task completion (default: 60)
 * @return PromiseInterface<TResult> Promise resolving to the task's return value
 *
 * @throws \RuntimeException If the task fails or times out
 * @throws \Hibla\Parallel\Serialization\SerializationException If task cannot be serialized
 */
function parallel(callable $task, array $context = [], int $timeout = 60): PromiseInterface
{
    return async(function () use ($task, $context, $timeout) {
        $process = Process::spawn($task, $context);
        
        return await($process->await($timeout));
    });
}