<?php

/**
 * Hibla Parallel Worker Script (Single-Task, Stream-Based with Real-Time Output) worker.php
 */

declare(strict_types=1);

// ===== CRITICAL: FORK BOMB PROTECTION =====
// Set environment variables to prevent nested process spawning
putenv('DEFER_BACKGROUND_PROCESS=1');
$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';
$_SERVER['DEFER_BACKGROUND_PROCESS'] = '1';

$nestingLevel = (int) (getenv('DEFER_NESTING_LEVEL') ?: 0) + 1;
putenv("DEFER_NESTING_LEVEL={$nestingLevel}");
$_ENV['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;
$_SERVER['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;

if ($nestingLevel > 1) {
    fwrite(STDERR, "FATAL: Attempted to spawn process at nesting level {$nestingLevel}. This is a fork bomb! Exiting.\n");
    exit(1);
}
// ==========================================

$outputBuffer = '';

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
    if (!is_resource($stdout)) {
        return; 
    }
    
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @fwrite($stdout, $json . PHP_EOL); 
        @fflush($stdout);
    }
}

function update_status_file_with_output(): void
{
    global $statusFile, $startTime, $taskId, $outputBuffer;
    if ($statusFile === null) return;

    $existing = [];
    if (file_exists($statusFile)) {
        $content = @file_get_contents($statusFile);
        if ($content !== false) {
            $existing = json_decode($content, true) ?: [];
        }
    }

    $preservedFields = [
        'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        'callback_type' => $existing['callback_type'] ?? null,
        'context_size' => $existing['context_size'] ?? null,
    ];

    $statusData = array_merge(
        $preservedFields,
        [
            'task_id' => $taskId,
            'status' => $existing['status'] ?? 'RUNNING',
            'message' => $existing['message'] ?? 'Task is running',
            'pid' => getmypid(),
            'timestamp' => time(),
            'duration' => microtime(true) - $startTime,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'updated_at' => date('Y-m-d H:i:s'),
            'buffered_output' => $outputBuffer,
        ]
    );

    @file_put_contents($statusFile, json_encode($statusData, JSON_UNESCAPED_SLASHES));
}

function update_status_file(string $status, string $message, array $extra = []): void
{
    global $statusFile, $startTime, $taskId, $outputBuffer;
    if ($statusFile === null) return;

    $existing = [];
    if (file_exists($statusFile)) {
        $content = @file_get_contents($statusFile);
        if ($content !== false) {
            $existing = json_decode($content, true) ?: [];
        }
    }

    $preservedFields = [
        'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s'),
        'callback_type' => $existing['callback_type'] ?? null,
        'context_size' => $existing['context_size'] ?? null,
    ];

    $statusData = array_merge(
        $preservedFields,
        [
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'pid' => getmypid(),
            'timestamp' => time(),
            'duration' => microtime(true) - $startTime,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'updated_at' => date('Y-m-d H:i:s'),
            'buffered_output' => $outputBuffer,
        ],
        $extra
    );

    @file_put_contents($statusFile, json_encode($statusData, JSON_UNESCAPED_SLASHES));
}

function stream_output_handler($buffer, $phase): string
{
    global $outputBuffer;
    
    if ($buffer !== '') {
        $outputBuffer .= $buffer;
        
        // Write to stdout for Linux
        write_status_to_stdout([
            'status' => 'OUTPUT',
            'output' => $buffer
        ]);
        
        // Also update status file for Windows
        update_status_file_with_output();
    }
    return '';
}

/**
 * Checks if the status file is in the system temp directory and deletes it.
 * This ensures cleanup happens even in fire-and-forget scenarios.
 */
function cleanup_temp_file(?string $file): void 
{
    if (!$file || !file_exists($file)) {
        return;
    }

    // Get real paths to ensure we are comparing correctly
    $fileReal = realpath($file);
    $tempReal = realpath(sys_get_temp_dir());

    if ($fileReal && $tempReal && strpos($fileReal, $tempReal) === 0) {
        // It is a temp file, delete it
        @unlink($file);

        // Try to remove the directory if it's empty (we created a subdir per task usually)
        $dir = dirname($file);
        $files = @scandir($dir);
        // if directory contains only . and ..
        if ($files !== false && count($files) <= 2) {
            @rmdir($dir);
        }
    }
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

    try {
        $taskData = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid task payload: " . json_last_error_msg());
        }

        $taskId = $taskData['task_id'] ?? 'unknown';
        $statusFile = $taskData['status_file'] ?? null;
        
        if ($statusFile) {
            $statusDir = dirname($statusFile);
            if (!is_dir($statusDir)) {
                $permissions = PHP_OS_FAMILY === 'Windows' ? 0777 : 0755;
                if (!@mkdir($statusDir, $permissions, true) && !is_dir($statusDir)) {
                    $error = error_get_last();
                    $errorMsg = $error ? $error['message'] : 'Unknown error';
                    throw new \RuntimeException("Worker failed to create status directory: {$statusDir}. Error: {$errorMsg}");
                }
            }
            
            if (!is_writable($statusDir)) {
                throw new \RuntimeException("Worker cannot write to status directory: {$statusDir}");
            }
        }
        
        if ($statusFile) {
            if (file_exists($statusFile)) {
                $initialStatus = @json_decode(file_get_contents($statusFile), true) ?: [];
                $statusData = array_merge($initialStatus, [
                    'status' => 'RECEIVED',
                    'message' => 'Worker received task payload and is initializing',
                    'pid' => getmypid(),
                    'timestamp' => time(),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'buffered_output' => '',
                ]);
                file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $statusData = [
                    'task_id' => $taskId,
                    'status' => 'RECEIVED',
                    'message' => 'Worker received task payload and is initializing',
                    'pid' => getmypid(),
                    'timestamp' => time(),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'buffered_output' => '',
                ];
                file_put_contents($statusFile, json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

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
        // Perform self-cleanup if we are running with a temp file
        // This ensures files are deleted even in fire-and-forget mode
        cleanup_temp_file($statusFile);
    }
}

if (is_resource($stdin)) fclose($stdin);
if (is_resource($stdout)) fclose($stdout);
if (is_resource($stderr)) fclose($stderr);

exit(0);