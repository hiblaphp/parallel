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

if ($nestingLevel > 1) exit(1);

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

if (empty($payload)) exit(1);

try {
    $taskData = json_decode($payload, true);
    if (!$taskData) exit(1);

    $loggingEnabled = $taskData['logging_enabled'] ?? false;
    $statusFile = $taskData['status_file'] ?? null;
    $taskId = $taskData['task_id'] ?? 'unknown';

    if (isset($taskData['autoload_path']) && file_exists($taskData['autoload_path'])) {
        require_once $taskData['autoload_path'];
    }

    if (isset($taskData['framework_bootstrap']) && file_exists($taskData['framework_bootstrap'])) {
        $bootstrapFile = $taskData['framework_bootstrap'];
        eval($taskData['framework_init_code'] ?? '');
    }

    if ($loggingEnabled && $statusFile) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'RUNNING',
            'message' => 'Fire and forget task started',
            'updated_at' => date('Y-m-d H:i:s')
        ]));
    }

    $callback = eval("return {$taskData['callback_code']};");
    $context = eval("return {$taskData['context_code']};");

    if (is_callable($callback)) {
        call_user_func($callback, $context);
    }

    if ($loggingEnabled && $statusFile) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'COMPLETED',
            'message' => 'Task completed',
            'updated_at' => date('Y-m-d H:i:s')
        ]));
    }

} catch (\Throwable $e) {
    if ($loggingEnabled && isset($statusFile)) {
        @file_put_contents($statusFile, json_encode([
            'task_id' => $taskId,
            'status' => 'ERROR',
            'message' => $e->getMessage(),
            'updated_at' => date('Y-m-d H:i:s')
        ]));
    }
}