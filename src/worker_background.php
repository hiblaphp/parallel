<?php

/**
 * Hibla Parallel Fire-and-Forget Worker Script
 */

declare(strict_types=1);

putenv('DEFER_BACKGROUND_PROCESS=1');
$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';
$_SERVER['DEFER_BACKGROUND_PROCESS'] = '1';

$nestingLevel = (int) (getenv('DEFER_NESTING_LEVEL') ?: 0) + 1;
putenv("DEFER_NESTING_LEVEL={$nestingLevel}");
$_ENV['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;
$_SERVER['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;

if ($nestingLevel > 1) {
    exit(1);
}

$isWindows = PHP_OS_FAMILY === 'Windows';
$loggingEnabled = false;
$statusFile = null;
$taskId = 'unknown';
$startTime = microtime(true);
$createdAt = date('Y-m-d H:i:s');
$timeoutSeconds = 600;

register_shutdown_function(function () {
    global $statusFile, $taskId, $loggingEnabled, $startTime, $createdAt, $timeoutSeconds;

    if (! $loggingEnabled || ! $statusFile) {
        return;
    }

    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMessage = $error['message'];
        $isTimeout = stripos($errorMessage, 'Maximum execution time') !== false;

        if ($isTimeout) {
            $status = 'TIMEOUT';
            $message = 'Task exceeded maximum execution time: ' . $errorMessage;
            $duration = $timeoutSeconds + (mt_rand(0, 100000) / 1000000);
        } else {
            $status = 'ERROR';
            $message = 'Fatal Error: ' . $errorMessage;
            $duration = microtime(true) - $startTime;
        }

        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'error' => $error,
            'duration' => $duration,
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
});

$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);

$payload = '';
$start = microtime(true);
while (microtime(true) - $start < 2.0) {
    $line = fgets($stdin);
    if ($line !== false) {
        $payload = $line;

        break;
    }
    usleep(10000);
}

if (empty($payload)) {
    exit(1);
}

try {
    $taskData = json_decode($payload, true);
    if (! $taskData) {
        exit(1);
    }

    $loggingEnabled = $taskData['logging_enabled'] ?? false;
    $statusFile = $taskData['status_file'] ?? null;
    $taskId = $taskData['task_id'] ?? 'unknown';
    $timeoutSeconds = $taskData['timeout_seconds'] ?? 60;
    $memoryLimit = $taskData['memory_limit'] ?? '512M';

    ini_set('memory_limit', $memoryLimit);
    ini_set('max_execution_time', (string)$timeoutSeconds);
    set_time_limit($timeoutSeconds);

    if (! $isWindows && function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGALRM, function () use ($taskId, $statusFile, $loggingEnabled, $startTime, $createdAt) {
            if ($loggingEnabled && $statusFile) {
                @file_put_contents($statusFile, json_encode([
                    'task_id' => $taskId,
                    'status' => 'TIMEOUT',
                    'message' => 'Task exceeded maximum execution time (wall-clock timeout)',
                    'duration' => microtime(true) - $startTime,
                    'memory_usage' => memory_get_usage(true),
                    'peak_memory_usage' => memory_get_peak_usage(true),
                    'created_at' => $createdAt,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            exit(124);
        });

        pcntl_alarm($timeoutSeconds);
    }

    if (isset($taskData['autoload_path']) && file_exists($taskData['autoload_path'])) {
        require_once $taskData['autoload_path'];
    }

    $serializationManager = new Rcalicdan\Serializer\CallbackSerializationManager();

    if (isset($taskData['framework_bootstrap']) && file_exists($taskData['framework_bootstrap'])) {
        $bootstrapFile = $taskData['framework_bootstrap'];
        $serializedBootstrapCallback = $taskData['framework_bootstrap_callback'] ?? null;

        if ($serializedBootstrapCallback !== null) {
            $bootstrapCallback = $serializationManager->unserializeCallback($serializedBootstrapCallback);
            $bootstrapCallback($bootstrapFile);
        } else {
            require $bootstrapFile;
        }
    }

    if ($loggingEnabled && $statusFile) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'RUNNING',
            'message' => 'Fire and forget task started',
            'pid' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);

    $callback();

    if (! $isWindows && function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }

    if ($loggingEnabled && $statusFile) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'COMPLETED',
            'message' => 'Task completed',
            'duration' => microtime(true) - $startTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
} catch (Throwable $e) {
    if (! $isWindows && function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }

    if ($loggingEnabled && isset($statusFile)) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'ERROR',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'duration' => microtime(true) - $startTime,
            'memory_usage' => memory_get_usage(true),
            'peak_memory_usage' => memory_get_peak_usage(true),
            'created_at' => $createdAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

exit(0);
