<?php

/**
 * Hibla Parallel Worker Script (Single-Task, Stream-Based with Real-Time Output)
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

// ===== CRASH DETECTION =====
$isProcessing = false;
$terminalFrameWritten = false;

register_shutdown_function(function () {
    global $isProcessing, $terminalFrameWritten, $taskId;

    // Only act if we died mid-task before a terminal frame was sent.
    // Covers: exit(N), silent OOM kills, unhandled signals, and fatal errors.
    if (! $isProcessing || $terminalFrameWritten) {
        return;
    }

    $error = error_get_last();
    $message = 'Worker process exited or crashed unexpectedly.';

    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $isTimeout = stripos($error['message'], 'Maximum execution time') !== false;
        $message = $isTimeout
            ? 'Task exceeded maximum execution time: ' . $error['message']
            : 'Fatal Error: ' . $error['message'];

        write_status_to_stdout([
            'status' => $isTimeout ? 'TIMEOUT' : 'ERROR',
            'class' => $isTimeout ? Hibla\Parallel\Exceptions\TimeoutException::class : Hibla\Parallel\Exceptions\ProcessCrashedException::class,
            'message' => $message,
            'code' => 0,
            'file' => $error['file'] ?? 'unknown',
            'line' => $error['line'] ?? 0,
            'stack_trace' => 'Worker crashed during execution.',
        ]);
    } else {
        write_status_to_stdout([
            'status' => 'ERROR',
            'class' => Hibla\Parallel\Exceptions\ProcessCrashedException::class,
            'message' => $message,
            'code' => 0,
            'file' => 'unknown',
            'line' => 0,
            'stack_trace' => 'Worker crashed during execution.',
        ]);
    }

    drain_and_wait();
});
// ===========================

$stdin = fopen('php://stdin',  'r');
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');

// Keep stdin blocking for the initial payload read to reliably wait for the
// parent to write the task payload. On Linux, anonymous pipes in non-blocking
// mode return false immediately if no data is ready yet, creating a race
// condition. Blocking mode eliminates this without requiring a polling loop.
// stdout and stderr remain non-blocking so the event loop is never starved.
stream_set_blocking($stdin,  true);
stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

$autoloadPath = null;
$taskId = 'unknown';
$startTime = microtime(true);
$serializationManager = null;

/**
 * Writes a JSON-encoded status message to stdout followed by a newline.
 * This is the sole communication channel back to the parent process.
 */
function write_status_to_stdout(array $data): void
{
    global $stdout;
    if (! is_resource($stdout)) {
        return;
    }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @fwrite($stdout, $json . PHP_EOL);
        @fflush($stdout);
    }
}

/**
 * Drains stdin after writing a terminal status frame.
 *
 * After the worker writes COMPLETED/ERROR/TIMEOUT to stdout it must not exit
 * immediately — on Windows the socket closes the instant the process exits,
 * which can destroy the final bytes before the parent's readLineAsync() has
 * finished consuming them. This function keeps the socket alive by blocking on
 * stdin until the parent closes its end (signalling it has read the result) or
 * a 500 ms safety timeout expires, whichever comes first.
 */
function drain_and_wait(): void
{
    global $stdin, $stdout;

    if (is_resource($stdout)) {
        @fflush($stdout);
    }

    if (! is_resource($stdin)) {
        return;
    }

    // Explicitly set non-blocking. $stdin was set to blocking at the top of
    // the file. If we leave it blocking and the parent crashes, fread() here
    // will hang indefinitely and zombie the child process.
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

/**
 * Output buffering callback that intercepts all echo/print output from the
 * task and forwards it to the parent as structured OUTPUT frames so the parent
 * can echo it in the correct order relative to its own output.
 */
function stream_output_handler(string $buffer, int $phase): string
{
    if ($buffer === '') {
        return '';
    }

    if (stripos($buffer, 'Maximum execution time') !== false) {
        write_status_to_stdout([
            'status' => 'TIMEOUT',
            'message' => 'Task exceeded maximum execution time (detected in output stream)',
            'output' => $buffer,
        ]);
        drain_and_wait();

        return '';
    }

    write_status_to_stdout([
        'status' => 'OUTPUT',
        'output' => $buffer,
    ]);

    return '';
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

// --- Main Worker Loop ---

$taskProcessed = false;

while (is_resource($stdin) && ! feof($stdin) && ! $taskProcessed) {
    // Blocking stdin ensures fgets() waits for the parent to write the payload
    // rather than returning false immediately on an empty pipe (Linux behaviour).
    $payload = fgets($stdin);

    if ($payload === false || trim($payload) === '') {
        // EOF or pipe closed — nothing to process.
        break;
    }

    // ── Mark task as in-flight so the shutdown function knows to send an
    //    ERROR frame if the process dies before it can write a terminal frame.
    $isProcessing = true;
    $terminalFrameWritten = false;

    ob_start('stream_output_handler', 1);

    $startTime = microtime(true);
    $taskId = 'unknown';

    try {
        $taskData = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid task payload: ' . json_last_error_msg());
        }

        $taskId = $taskData['task_id'] ?? 'unknown';
        $timeoutSeconds = $taskData['timeout_seconds'] ?? 60;
        $memoryLimit = $taskData['memory_limit'] ?? '512M';

        ini_set('memory_limit', $memoryLimit);
        ini_set('max_execution_time', (string)$timeoutSeconds);
        set_time_limit($timeoutSeconds);

        if (function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }

            pcntl_signal(SIGALRM, function () {
                global $terminalFrameWritten;
                $message = 'Task exceeded maximum execution time (wall-clock timeout)';

                write_status_to_stdout(['status' => 'TIMEOUT', 'message' => $message]);

                $terminalFrameWritten = true; // prevent double-write in shutdown

                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                drain_and_wait();
                exit(124);
            });

            pcntl_alarm($timeoutSeconds);
        }

        if ($autoloadPath === null) {
            $autoloadPath = $taskData['autoload_path'] ?? '';
            if (! file_exists($autoloadPath)) {
                throw new RuntimeException("Autoloader not found: {$autoloadPath}");
            }
            require_once $autoloadPath;

            // Mark this process as a streamed worker so emit() can write MESSAGE frames.
            Hibla\Parallel\Internals\WorkerContext::markAsWorker();

            $serializationManager = new Rcalicdan\Serializer\CallbackSerializationManager();

            $frameworkBootstrap = $taskData['framework_bootstrap'] ?? '';
            $serializedBootstrapCallback = $taskData['framework_bootstrap_callback'] ?? null;

            if ($frameworkBootstrap && file_exists($frameworkBootstrap)) {
                if ($serializedBootstrapCallback !== null) {
                    $bootstrapCallback = $serializationManager->unserializeCallback($serializedBootstrapCallback);
                    $bootstrapCallback($frameworkBootstrap);
                } else {
                    require $frameworkBootstrap;
                }
            }
        }

        try {
            $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to unserialize task data: ' . $e->getMessage(), 0, $e);
        }

        if (! is_callable($callback)) {
            throw new RuntimeException('Deserialized task is not callable.');
        }

        $result = Hibla\await(Hibla\async($callback));
        Hibla\EventLoop\Loop::run();
        ob_end_flush();

        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        $needsSerialization = is_object($result) || is_resource($result) ||
            (is_array($result) && containsObjects($result));

        $finalStatus = $needsSerialization
            ? ['status' => 'COMPLETED', 'result' => base64_encode(serialize($result)), 'result_serialized' => true]
            : ['status' => 'COMPLETED', 'result' => $result, 'result_serialized' => false];

        write_status_to_stdout($finalStatus);
        $terminalFrameWritten = true; // ← clean exit, shutdown function should no-op

        drain_and_wait();
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        $errorStatus = [
            'status' => 'ERROR',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ];

        write_status_to_stdout($errorStatus);
        $terminalFrameWritten = true; // ← handled error, shutdown function should no-op

        drain_and_wait();
    } finally {
        $taskProcessed = true;
    }
}

if (is_resource($stdin)) {
    fclose($stdin);
}
if (is_resource($stdout)) {
    fclose($stdout);
}
if (is_resource($stderr)) {
    fclose($stderr);
}

exit(0);
