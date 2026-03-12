<?php

/**
 * Hibla Parallel Persistent Worker Script
 */

declare(strict_types=1);

// ===== CRITICAL: FORK BOMB PROTECTION =====
putenv('DEFER_BACKGROUND_PROCESS=1');
$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';
$_SERVER['DEFER_BACKGROUND_PROCESS'] = '1';

$maxNestingLevel = (int)(getenv('HIBLA_MAX_NESTING_LEVEL') ?: 3);
$nestingLevel = (int)(getenv('DEFER_NESTING_LEVEL') ?: 0) + 1;
putenv("DEFER_NESTING_LEVEL={$nestingLevel}");

if ($nestingLevel > $maxNestingLevel) {
    fwrite(STDERR, "FATAL: Nesting level {$nestingLevel} exceeds maximum.\n");
    exit(1);
}
// ==========================================

$stdin = fopen('php://stdin', 'r');
$stdout = fopen('php://stdout', 'w');
$stderr = fopen('php://stderr', 'w');

// Persistent workers MUST block on read so they don't consume 100% CPU while idle
stream_set_blocking($stdin, true);
stream_set_blocking($stdout, false);
stream_set_blocking($stderr, false);

function write_frame(array $data): void {
    global $stdout;
    if (is_resource($stdout)) {
        @fwrite($stdout, json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        @fflush($stdout);
    }
}

// 1. Read Boot Payload (Autoloader, Bootstrap, Limits)
$bootPayload = fgets($stdin);
if (!$bootPayload) exit(1);

$bootData = json_decode($bootPayload, true);
ini_set('memory_limit', $bootData['memory_limit'] ?? '512M');

if (!empty($bootData['autoload_path']) && file_exists($bootData['autoload_path'])) {
    require_once $bootData['autoload_path'];
}

$serializationManager = new Rcalicdan\Serializer\CallbackSerializationManager();

if (!empty($bootData['framework_bootstrap']) && file_exists($bootData['framework_bootstrap'])) {
    if (!empty($bootData['framework_bootstrap_callback'])) {
        $bootstrapCallback = $serializationManager->unserializeCallback($bootData['framework_bootstrap_callback']);
        $bootstrapCallback($bootData['framework_bootstrap']);
    } else {
        require $bootData['framework_bootstrap'];
    }
}

// 2. Tell the parent the worker is ready to accept tasks
write_frame(['status' => 'READY']);

// 3. The Persistent Event Loop
while (($payload = fgets($stdin)) !== false) {
    if (trim($payload) === '') continue;

    $taskData = json_decode($payload, true);
    if (!$taskData || !isset($taskData['task_id'])) continue;

    $taskId = $taskData['task_id'];
    
    // Setup Output Buffering to capture echo/print and route it to the specific task_id
    ob_start(function (string $buffer) use ($taskId) {
        if ($buffer !== '') {
            write_frame(['status' => 'OUTPUT', 'task_id' => $taskId, 'output' => $buffer]);
        }
        return '';
    }, 1);

    try {
        $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);
        
        // Execute the task
        $result = Hibla\await(Hibla\async($callback));
        ob_end_flush(); // Flush any remaining output

        // Serialize and send result
        $needsSerialization = is_object($result) || is_resource($result) || is_array($result);
        
        write_frame([
            'status' => 'COMPLETED',
            'task_id' => $taskId,
            'result' => $needsSerialization ? base64_encode(serialize($result)) : $result,
            'result_serialized' => $needsSerialization
        ]);

    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        
        write_frame([
            'status' => 'ERROR',
            'task_id' => $taskId,
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
        ]);
    }

    // Clean up memory after every task to prevent persistent memory leaks
    gc_collect_cycles();
    
    // Signal that this worker is available for the next task
    write_frame(['status' => 'READY']);
}

// Clean exit when STDIN is closed by the parent
exit(0);