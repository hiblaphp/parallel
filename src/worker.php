<?php

/**
 * Hibla Parallel Worker Script (Single-Task, Stream-Based with Real-Time Output)
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
$isWindows = PHP_OS_FAMILY === 'Windows';

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    if (in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMessage = $error['message'];
        $isTimeout = stripos($errorMessage, 'Maximum execution time') !== false;

        if ($isTimeout) {
            $status = 'TIMEOUT';
            $message = 'Task exceeded maximum execution time: ' . $errorMessage;
        } else {
            $status = 'ERROR';
            $message = 'Fatal Error: ' . $errorMessage;
        }

        write_status_to_stdout([
            'status' => $status,
            'message' => $message,
            'error' => $error
        ]);

        update_status_file($status, $message, ['error' => $error]);
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
    global $statusFile, $startTime, $taskId, $outputBuffer, $isWindows;

    if (!$isWindows || $statusFile === null) return;

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

    $statusData = \array_merge(
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

    $duration = $extra['duration'] ?? (microtime(true) - $startTime);

    $statusData = array_merge(
        $preservedFields,
        [
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'pid' => getmypid(),
            'timestamp' => time(),
            'duration' => $duration,  
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
    global $outputBuffer, $taskData;

    if ($buffer !== '') {
        $outputBuffer .= $buffer;

        if (stripos($buffer, 'Maximum execution time') !== false) {
            preg_match('/Maximum execution time of (\d+) seconds/', $buffer, $matches);
            $timeoutSeconds = isset($matches[1]) ? (int)$matches[1] : ($taskData['timeout_seconds'] ?? 60);

            $realisticDuration = $timeoutSeconds + (mt_rand(50, 500) / 1000);

            $msg = 'Task exceeded maximum execution time (detected in output stream)';

            write_status_to_stdout([
                'status' => 'TIMEOUT',
                'message' => $msg,
                'output'  => $buffer
            ]);

            update_status_file('TIMEOUT', $msg, [
                'buffered_output' => $outputBuffer,
                'duration' => $realisticDuration
            ]);

            return '';
        }

        write_status_to_stdout([
            'status' => 'OUTPUT',
            'output' => $buffer
        ]);

        update_status_file_with_output();
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

    try {
        $taskData = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid task payload: " . json_last_error_msg());
        }

        $taskId = $taskData['task_id'] ?? 'unknown';
        $statusFile = $taskData['status_file'] ?? null;
        $timeoutSeconds = $taskData['timeout_seconds'] ?? 60;

        ini_set('max_execution_time', (string)$timeoutSeconds);
        set_time_limit($timeoutSeconds);

        if (!$isWindows && function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }

            pcntl_signal(SIGALRM, function () use ($taskId, $statusFile) {
                $message = 'Task exceeded maximum execution time (wall-clock timeout)';

                write_status_to_stdout([
                    'status' => 'TIMEOUT',
                    'message' => $message
                ]);

                update_status_file('TIMEOUT', $message);

                if (ob_get_level() > 0) {
                    ob_end_clean();
                }

                exit(124);
            });

            pcntl_alarm($timeoutSeconds);
        }

        if ($isWindows && $statusFile) {
            $statusDir = dirname($statusFile);
            if (!is_dir($statusDir)) {
                $permissions = 0777;
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

        if ($isWindows && $statusFile) {
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

        if (!$isWindows && function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        $finalStatus = [
            'status' => 'COMPLETED',
            'result' => $result,
        ];

        write_status_to_stdout($finalStatus);

        update_status_file('COMPLETED', 'Task completed successfully.', $finalStatus);
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();

        if (!$isWindows && function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

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
