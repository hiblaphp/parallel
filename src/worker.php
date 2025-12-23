<?php

/**
 * Hibla Parallel Worker Script (Single-Task, Stream-Based with Real-Time Output)
 */

declare(strict_types=1);

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

function write_status_to_stdout(array $data): void
{
    global $stdout;
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        fwrite($stdout, $json . PHP_EOL);
        fflush($stdout);
    }
}

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

function stream_output_handler($buffer, $phase): string
{
    if ($buffer !== '') {
        write_status_to_stdout([
            'status' => 'OUTPUT',
            'output' => $buffer
        ]);
    }
    return '';
}

// --- Main Worker Loop (Single Task) ---

$taskProcessed = false;
$maxWaitTime = 5;
$waitStart = microtime(true);

while (is_resource($stdin) && !feof($stdin) && !$taskProcessed) {
    $payload = fgets($stdin);
    
    if ($payload === false || trim($payload) === '') {
        if ((microtime(true) - $waitStart) > $maxWaitTime) {
            fwrite($stderr, "Worker timeout: No task received within {$maxWaitTime} seconds.\n");
            break;
        }
        
        usleep(10000);
        continue;
    }

    ob_start('stream_output_handler', 1);

    $startTime = microtime(true);
    $taskId = 'unknown';
    $allOutput = '';

    try {
        $taskData = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid task payload: " . json_last_error_msg());
        }

        $taskId = $taskData['task_id'] ?? 'unknown';
        $statusFile = $taskData['status_file'] ?? null;

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
        
        $callback = eval("return {$taskData['callback_code']};");
        $context = eval("return {$taskData['context_code']};");

        if (!is_callable($callback)) {
            throw new \RuntimeException('Deserialized task is not callable.');
        }
        
        write_status_to_stdout(['status' => 'RUNNING']);
        
        $result = $callback($context);
        ob_end_flush();
        
        $finalStatus = [
            'status' => 'COMPLETED',
            'result' => $result,
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
            'stack_trace' => $e->getTraceAsString()
        ];

        write_status_to_stdout($errorStatus);
        update_status_file('ERROR', $e->getMessage(), $errorStatus);
    } finally {
        $taskProcessed = true;
    }
}

if (is_resource($stdin)) fclose($stdin);
if (is_resource($stdout)) fclose($stdout);
if (is_resource($stderr)) fclose($stderr);

exit(0);