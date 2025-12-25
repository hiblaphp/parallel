<?php

/**
 * Hibla Parallel Fire-and-Forget Worker Script
 * Optimized for execution without parent monitoring.
 */

declare(strict_types=1);

// ===== CRITICAL: FORK BOMB PROTECTION =====
putenv('DEFER_BACKGROUND_PROCESS=1');
$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';
$_SERVER['DEFER_BACKGROUND_PROCESS'] = '1';

$nestingLevel = (int) (getenv('DEFER_NESTING_LEVEL') ?: 0) + 1;
putenv("DEFER_NESTING_LEVEL={$nestingLevel}");
$_ENV['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;
$_SERVER['DEFER_NESTING_LEVEL'] = (string) $nestingLevel;

if ($nestingLevel > 1) {
    exit(1); // Silent exit for safety
}
// ==========================================

// Standard inputs
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);

// We generally ignore stdout/stderr in fire-and-forget unless logging errors
$stderr = fopen('php://stderr', 'w');

$autoloadPath = null;
$statusFile = null;
$taskId = 'unknown';
$loggingEnabled = false;

// Function to log only if enabled
function update_status_file(string $status, string $message, array $extra = []): void
{
    global $statusFile, $taskId, $loggingEnabled;
    
    // If logging is disabled, we do absolutely nothing with files
    if (!$loggingEnabled || $statusFile === null) {
        return;
    }

    $existing = [];
    if (file_exists($statusFile)) {
        $content = @file_get_contents($statusFile);
        if ($content !== false) {
            $existing = json_decode($content, true) ?: [];
        }
    }

    $statusData = array_merge(
        $existing,
        [
            'task_id' => $taskId,
            'status' => $status,
            'message' => $message,
            'updated_at' => date('Y-m-d H:i:s'),
        ],
        $extra
    );

    @file_put_contents($statusFile, json_encode($statusData, JSON_UNESCAPED_SLASHES));
}

// Wait briefly for payload
$payload = '';
$start = microtime(true);
while (microtime(true) - $start < 2.0) {
    $line = fgets($stdin);
    if ($line !== false) {
        $payload = $line;
        break;
    }
    usleep(10000); // 10ms
}

if (empty($payload)) {
    exit(1);
}

try {
    $taskData = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        exit(1);
    }

    $taskId = $taskData['task_id'] ?? 'unknown';
    $statusFile = $taskData['status_file'] ?? null;
    $loggingEnabled = $taskData['logging_enabled'] ?? false;

    // Load Autoloader
    $autoloadPath = $taskData['autoload_path'] ?? '';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    // Framework Bootstrap
    $frameworkBootstrap = $taskData['framework_bootstrap'] ?? '';
    $frameworkInitCode = $taskData['framework_init_code'] ?? '';
    if ($frameworkBootstrap && file_exists($frameworkBootstrap)) {
        $bootstrapFile = $frameworkBootstrap;
        eval($frameworkInitCode);
    }

    update_status_file('RUNNING', 'Fire and forget task started');

    // Deserialize and Run
    $callback = eval("return {$taskData['callback_code']};");
    $context = eval("return {$taskData['context_code']};");

    if (is_callable($callback)) {
        call_user_func($callback, $context);
    }

    update_status_file('COMPLETED', 'Task completed');

} catch (\Throwable $e) {
    // Only write to status file if logging is actually on
    update_status_file('ERROR', $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    // If logging is disabled but an error occurred, writing to stderr 
    // might be the only way to catch it in system logs
    fwrite($stderr, "Defer Task Error [$taskId]: " . $e->getMessage() . PHP_EOL);
} finally {
    // Cleanup: If logging is disabled, ensure no status file remains 
    // (In case the Manager created a placeholder, though it shouldn't have)
    if (!$loggingEnabled && $statusFile && file_exists($statusFile)) {
        @unlink($statusFile);
    }
}