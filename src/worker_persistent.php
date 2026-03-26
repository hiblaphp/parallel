<?php

/**
 * Hibla Parallel Persistent Worker Script
 *
 * Designed to stay alive across multiple task executions to avoid the
 * latency of repeated process spawning and framework bootstrapping.
 */

declare(strict_types=1);

// ===== CRITICAL: FORK BOMB PROTECTION =====
putenv('DEFER_BACKGROUND_PROCESS=1');
$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';
$_SERVER['DEFER_BACKGROUND_PROCESS'] = '1';

// Read from $argv[1] primarily, fallback to getenv
$maxNestingLevel = (int)($argv[1] ?? getenv('HIBLA_MAX_NESTING_LEVEL') ?: 3);

putenv("HIBLA_MAX_NESTING_LEVEL={$maxNestingLevel}");
$_ENV['HIBLA_MAX_NESTING_LEVEL'] = (string)$maxNestingLevel;
$_SERVER['HIBLA_MAX_NESTING_LEVEL'] = (string)$maxNestingLevel;

// Increment the current level
$nestingLevel = (int)($_SERVER['DEFER_NESTING_LEVEL'] ?? $_ENV['DEFER_NESTING_LEVEL'] ?? getenv('DEFER_NESTING_LEVEL') ?: 0) + 1;
putenv("DEFER_NESTING_LEVEL={$nestingLevel}");
$_ENV['DEFER_NESTING_LEVEL'] = (string)$nestingLevel;
$_SERVER['DEFER_NESTING_LEVEL'] = (string)$nestingLevel;

if ($nestingLevel > $maxNestingLevel) {
    fwrite(STDERR, "FATAL: Nesting level {$nestingLevel} exceeds maximum.\n");
    exit(1);
}
// ==========================================

$stdin = fopen('php://stdin',  'r');
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');

// Keep stdin blocking throughout. For the boot payload read this eliminates
// the Linux race condition where non-blocking fgets() returns false immediately
// on an empty pipe. For the task loop fgets() this is also safe — the event
// loop only runs during task execution inside await(), never between tasks,
// so there is nothing to starve while waiting for the next payload.
stream_set_blocking($stdin,  true);
stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

// ===== PROCESS-LEVEL STATE =====
// These are declared at script scope and accessed via global in closures
// and nested scopes — consistent with how $currentTaskId and $isProcessing
// are managed throughout this script.
$currentTaskId = null;   // task ID of the currently executing task
$isProcessing = false;  // true while a task is in-flight
$executionCount = 0;      // number of tasks completed by this worker
$maxExecutionsPerWorker = null;   // null = unlimited, set from boot payload
// ================================

/**
 * Writes a JSON-encoded status message to stdout followed by a newline.
 */
function write_frame(array $data): void
{
    global $stdout;
    if (is_resource($stdout)) {
        @fwrite($stdout, json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        @fflush($stdout);
    }
}

/**
 * Drains stdin after writing a terminal status frame or during shutdown.
 *
 * Crucial for Windows compatibility: When a process exits, Windows instantly
 * destroys open socket descriptors, which can wipe out the final bytes in the
 * output buffer before the parent process has finished reading them.
 *
 * This keeps the child process alive until the parent explicitly closes its end
 * of the pipe (signalling it has safely read the output) or a 500ms safety
 * timeout expires.
 */
function drain_and_wait(): void
{
    // Drain-and-wait is only necessary on Windows. When a process exits,
    // Windows instantly destroys its socket descriptors, which can wipe out
    // bytes still in the OS transmit buffer before the parent reads them.
    // On Unix, anonymous pipes are kernel-buffered and survive the writer
    // exiting — the parent will always drain the remaining bytes safely.
    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }

    global $stdin, $stdout;

    if (is_resource($stdout)) {
        @fflush($stdout);
    }

    if (! is_resource($stdin)) {
        return;
    }

    // Explicitly set to non-blocking so the 500ms timeout loop doesn't hang
    // if the parent process itself crashed and stopped writing/closing.
    @stream_set_blocking($stdin, false);

    $drainStart = hrtime(true);
    while ((hrtime(true) - $drainStart) < 500_000_000) { // 500ms
        $chunk = @fread($stdin, 1);
        if ($chunk === false || feof($stdin)) {
            break; // Parent closed the pipe, safe to exit
        }
        usleep(5000); // 5ms sleep to prevent CPU spin
    }
}

function containsObjects(mixed $value): bool
{
    if (is_object($value)) {
        return true;
    }
    if (! is_array($value)) {
        return false;
    }

    foreach ($value as $item) {
        if (is_object($item) || (is_array($item) && containsObjects($item))) {
            return true;
        }
    }

    return false;
}

// ===== CRASH DETECTION =====
register_shutdown_function(function () {
    global $currentTaskId, $isProcessing, $executionCount, $maxExecutionsPerWorker;

    // If the worker retired gracefully via exit(0) or died while idle, no
    // ERROR frame is needed, but we MUST still drain_and_wait() to ensure
    // the parent receives the prior RETIRING or COMPLETED frame safely on Windows.
    if (! $isProcessing || $currentTaskId === null) {
        drain_and_wait();

        return;
    }

    $error = error_get_last();
    $message = 'Worker process exited or crashed unexpectedly.';
    $isTimeout = false;

    if ($error !== null && in_array($error['type'],[E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $isTimeout = stripos($error['message'], 'Maximum execution time') !== false;
        $message = $isTimeout
            ? 'Task exceeded maximum execution time: ' . $error['message']
            : 'Fatal Error: ' . $error['message'];
    }

    // Reject the in-flight task promise on the parent side. We use TimeoutException
    // when a timeout is detected so the parent runner can process it correctly.
    write_frame([
        'status' => 'ERROR',
        'task_id' => $currentTaskId,
        'class' => $isTimeout ? Hibla\Parallel\Exceptions\TimeoutException::class : Hibla\Parallel\Exceptions\ProcessCrashedException::class,
        'message' => $message,
        'code' => 0,
        'file' => $error['file'] ?? 'unknown',
        'line' => $error['line'] ?? 0,
        'stack_trace' => 'Worker crashed during execution.',
    ]);

    // Signal the parent to terminate this worker and respawn a replacement
    write_frame(['status' => 'CRASHED']);

    // Ensure the CRASHED frame is fully transmitted before the socket dies
    drain_and_wait();
});
// ===========================

// 1. Read Boot Payload (Autoloader, Bootstrap, Limits, Retirement Config)
$bootPayload = fgets($stdin);
if (! $bootPayload) {
    exit(1);
}

$bootData = json_decode($bootPayload, true);
$timeoutSeconds = $bootData['timeout_seconds'] ?? 60;
$memoryLimit = $bootData['memory_limit'] ?? '512M';
set_time_limit($timeoutSeconds);
ini_set('memory_limit', $memoryLimit);

if (! empty($bootData['autoload_path']) && file_exists($bootData['autoload_path'])) {
    require_once $bootData['autoload_path'];
}

// Mark as worker immediately after autoload so emit() can write MESSAGE
// frames. Must be unconditional — framework bootstrap is optional but
// message passing is always available in persistent workers.
Hibla\Parallel\Internals\WorkerContext::markAsWorker();

$serializationManager = new Rcalicdan\Serializer\CallbackSerializationManager();

if (! empty($bootData['framework_bootstrap']) && file_exists($bootData['framework_bootstrap'])) {
    if (! empty($bootData['framework_bootstrap_callback'])) {
        $bootstrapCallback = $serializationManager->unserializeCallback($bootData['framework_bootstrap_callback']);
        $bootstrapCallback($bootData['framework_bootstrap']);
    } else {
        require $bootData['framework_bootstrap'];
    }
}

// Set the retirement threshold from the boot payload.
// Only active when explicitly configured via withMaxExecutionsPerWorker() —
// null means unlimited, workers run indefinitely until the pool shuts down.
global $maxExecutionsPerWorker;
$maxExecutionsPerWorker = isset($bootData['max_executions_per_worker'])
    && \is_int($bootData['max_executions_per_worker'])
    && $bootData['max_executions_per_worker'] > 0
    ? $bootData['max_executions_per_worker']
    : null;

// Tell the parent the worker is ready to accept tasks
write_frame(['status' => 'READY', 'pid' => getmypid()]);

// ===== TASK LOOP =====
while (($payload = fgets($stdin)) !== false) {
    if (trim($payload) === '') {
        continue;
    }

    $taskData = json_decode($payload, true);
    if (! $taskData || ! isset($taskData['task_id'])) {
        continue;
    }

    global $currentTaskId, $isProcessing;
    $currentTaskId = $taskData['task_id'];
    $isProcessing = true;
    
    $taskTimeout = $taskData['timeout_seconds'] ?? $timeoutSeconds;
    set_time_limit($taskTimeout);

    if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGALRM, function () {
            global $currentTaskId;
            $message = 'Task exceeded maximum execution time (wall-clock timeout)';

            write_frame([
                'status' => 'ERROR',
                'task_id' => $currentTaskId,
                'class' => Hibla\Parallel\Exceptions\TimeoutException::class,
                'message' => $message,
                'code' => 0,
                'file' => 'unknown',
                'line' => 0,
                'stack_trace' => 'Worker timed out.',
            ]);
            write_frame(['status' => 'CRASHED']);

            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            drain_and_wait();
            exit(124);
        });

        pcntl_alarm($taskTimeout);
    }
    // ---------------------------

    // Register the task ID so emit() can tag MESSAGE frames for correct
    // routing back to the originating task handler on the parent side.
    Hibla\Parallel\Internals\WorkerContext::setCurrentTaskId($currentTaskId);

    ob_start(function (string $buffer) use ($currentTaskId) {
        if ($buffer === '') {
            return '';
        }

        if (stripos($buffer, 'Maximum execution time') !== false) {
            write_frame([
                'status' => 'ERROR',
                'task_id' => $currentTaskId,
                'class' => Hibla\Parallel\Exceptions\TimeoutException::class,
                'message' => 'Task exceeded maximum execution time (detected in output stream)',
                'code' => 0,
                'file' => 'unknown',
                'line' => 0,
                'stack_trace' => 'Worker timed out.',
            ]);
            write_frame(['status' => 'CRASHED']);
            drain_and_wait();
            
            return '';
        }

        write_frame(['status' => 'OUTPUT', 'task_id' => $currentTaskId, 'output' => $buffer]);

        return '';
    }, 1);

    try {
        $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);
        $result = Hibla\await(Hibla\async($callback));
        ob_end_flush();
        Hibla\EventLoop\Loop::run();

        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        $needsSerialization = is_resource($result) || containsObjects($result);

        write_frame([
            'status' => 'COMPLETED',
            'task_id' => $currentTaskId,
            'result' => $needsSerialization ? base64_encode(serialize($result)) : $result,
            'result_serialized' => $needsSerialization,
        ]);
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        write_frame([
            'status' => 'ERROR',
            'task_id' => $currentTaskId,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ]);
    }

    // Task finished cleanly — clear crash context and task ID
    $isProcessing = false;
    $currentTaskId = null;

    // Clear WorkerContext so emit() no-ops between tasks rather than
    // tagging orphaned frames with a stale task ID.
    Hibla\Parallel\Internals\WorkerContext::setCurrentTaskId(null);

    gc_collect_cycles();

    // ===== RETIREMENT CHECK =====
    // Increment the execution counter and check against the configured threshold.
    // Only active when max_executions_per_worker was explicitly set in the boot
    // payload — null means unlimited and this block is skipped entirely.
    // RETIRING is written instead of READY — the parent treats this as a planned
    // respawn, not a crash, so no ProcessCrashedException is thrown.
    global $executionCount, $maxExecutionsPerWorker;

    if ($maxExecutionsPerWorker !== null) {
        $executionCount++;

        if ($executionCount >= $maxExecutionsPerWorker) {
            write_frame([
                'status' => 'RETIRING',
                'executions' => $executionCount,
            ]);

            // Exit cleanly — the parent will spawn a fresh replacement worker.
            // exit(0) automatically fires the register_shutdown_function, which
            // runs drain_and_wait() to guarantee the RETIRING frame is received.
            exit(0);
        }
    }
    // ============================

    write_frame(['status' => 'READY', 'pid' => getmypid()]);
}

exit(0);