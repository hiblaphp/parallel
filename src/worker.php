<?php

/**
 * Hibla Parallel Worker Script (Single-Task, Stream-Based)
 *
 * This script runs as a child process, managed by proc_open. It is the
 * universal entry point for all parallel tasks. It reads ONE serialized task
 * definition from STDIN, executes it, writes the result as JSON to STDOUT,
 * and then exits cleanly.
 */

declare(strict_types=1);


// Set an error handler to catch fatal errors and report them.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        write_status_to_stdout(['status' => 'ERROR', 'message' => 'Fatal Error: ' . $error['message']]);
        update_status_file('ERROR', 'Fatal Error: ' . $error['message'], ['error' => $error]);
    }
});

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');

stream_set_blocking($stdin, false);
stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

$autoloadPath = null;
$statusFile = null;
$taskId = 'unknown';
$startTime = microtime(true);

/**
 * Writes a status message to STDOUT for the parent process.
 */
function write_status_to_stdout(array $data): void
{
    global $stdout;
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        fwrite($stdout, $json . PHP_EOL);
        fflush($stdout);
    }
}

/**
 * Writes a detailed status message to the filesystem for out-of-band monitoring.
 */
function update_status_file(string $status, string $message, array $extra = []): void
{
    global $statusFile, $startTime, $taskId;
    if ($statusFile === null) return;

    $statusData = array_merge([
        'task_id' => $taskId,
        'status' => $status,
        'message' => $message,
        'pid' => getmypid(),
        'timestamp' => time(),
        'duration' => microtime(true) - $startTime,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
    ], $extra);

    file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}


// --- Main Worker Loop (Single Task) ---

$taskProcessed = false;
$maxWaitTime = 5; // Maximum seconds to wait for initial task
$waitStart = microtime(true);

while (is_resource($stdin) && !feof($stdin) && !$taskProcessed) {
    $payload = fgets($stdin);
    
    if ($payload === false || trim($payload) === '') {
        // Check if we've been waiting too long for a task
        if ((microtime(true) - $waitStart) > $maxWaitTime) {
            fwrite($stderr, "Worker timeout: No task received within {$maxWaitTime} seconds.\n");
            break;
        }
        
        usleep(10000); // 10ms
        continue;
    }

    $capturedOutput = '';
    ob_start(function ($buffer) use (&$capturedOutput) {
        $capturedOutput .= $buffer;
        return '';
    });

    // Reset tracking variables for the task
    $startTime = microtime(true);
    $taskId = 'unknown';

    try {
        $taskData = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid task payload: " . json_last_error_msg());
        }

        $taskId = $taskData['task_id'] ?? 'unknown';
        $statusFile = $taskData['status_file'] ?? null;

        // --- Environment Bootstrap (only on the first task this worker receives) ---
        if ($autoloadPath === null) {
            $autoloadPath = $taskData['autoload_path'] ?? '';
            if (!file_exists($autoloadPath)) {
                throw new \RuntimeException("Autoloader not found: {$autoloadPath}");
            }
            require_once $autoloadPath;
            
            $frameworkBootstrap = $taskData['framework_bootstrap'] ?? '';
            $frameworkInitCode = $taskData['framework_init_code'] ?? '';
            if ($frameworkBootstrap && file_exists($frameworkBootstrap)) {
                $bootstrapFile = $frameworkBootstrap;
                eval($frameworkInitCode);
            }
        }
        
        update_status_file('RUNNING', 'Worker process started execution for task: ' . $taskId);
        
        // --- Task Deserialization and Execution ---
        
        $callback = eval("return {$taskData['callback_code']};");
        $context = eval("return {$taskData['context_code']};");

        if (!is_callable($callback)) {
            throw new \RuntimeException('Deserialized task is not callable.');
        }
        
        // Signal parent that we are running (critical for non-blocking wait)
        write_status_to_stdout(['status' => 'RUNNING']);
        
        $result = $callback($context);
        ob_end_flush();
        
        $finalStatus = [
            'status' => 'COMPLETED',
            'result' => $result,
            'output' => $capturedOutput,
        ];
        
        write_status_to_stdout($finalStatus);
        update_status_file('COMPLETED', 'Task completed successfully.', $finalStatus);

    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();

        $errorStatus = [
            'status' => 'ERROR',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'output' => $capturedOutput,
            'stack_trace' => $e->getTraceAsString()
        ];

        write_status_to_stdout($errorStatus);
        update_status_file('ERROR', $e->getMessage(), $errorStatus);
    } finally {
        $taskProcessed = true;
    }
}

// --- Cleanup and Exit ---
if (is_resource($stdin)) fclose($stdin);
if (is_resource($stdout)) fclose($stdout);
if (is_resource($stderr)) fclose($stderr);

exit(0);