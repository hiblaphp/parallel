<?php

/**
 * Hibla Parallel Persistent Worker Script
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

function write_frame(array $data): void
{
    global $stdout;
    if (is_resource($stdout)) {
        @fwrite($stdout, json_encode($data, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        @fflush($stdout);
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
$currentTaskId = null;
$isProcessing = false;

register_shutdown_function(function () {
    global $currentTaskId, $isProcessing;

    // Only signal a crash if this worker died mid-task; idle crashes need no ERROR frame
    if ($isProcessing && $currentTaskId !== null) {
        $error = error_get_last();
        $message = 'Worker process exited or crashed unexpectedly.';

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $message = 'Fatal Error: ' . $error['message'];
        }

        // Reject the in-flight task promise on the parent side
        write_frame([
            'status' => 'ERROR',
            'task_id' => $currentTaskId,
            'class' => Hibla\Parallel\Exceptions\ProcessCrashedException::class,
            'message' => $message,
            'code' => 0,
            'file' => $error['file'] ?? 'unknown',
            'line' => $error['line'] ?? 0,
            'stack_trace' => 'Worker crashed during execution.',
        ]);

        // Signal the parent to terminate this worker and respawn a replacement
        write_frame(['status' => 'CRASHED']);
    }
});
// ===========================

// 1. Read Boot Payload (Autoloader, Bootstrap, Limits)
$bootPayload = fgets($stdin);
if (! $bootPayload) {
    exit(1);
}

$bootData = json_decode($bootPayload, true);
ini_set('memory_limit', $bootData['memory_limit'] ?? '512M');

if (! empty($bootData['autoload_path']) && file_exists($bootData['autoload_path'])) {
    require_once $bootData['autoload_path'];
}

// Mark this process as a worker for emit to properly send message
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

//Tell the parent the worker is ready to accept tasks
write_frame(['status' => 'READY']);

while (($payload = fgets($stdin)) !== false) {
    if (trim($payload) === '') {
        continue;
    }

    $taskData = json_decode($payload, true);
    if (! $taskData || ! isset($taskData['task_id'])) {
        continue;
    }

    $currentTaskId = $taskData['task_id'];
    $isProcessing = true;

    Hibla\Parallel\Internals\WorkerContext::setCurrentTaskId($currentTaskId);

    ob_start(function (string $buffer) use ($currentTaskId) {
        if ($buffer !== '') {
            write_frame(['status' => 'OUTPUT', 'task_id' => $currentTaskId, 'output' => $buffer]);
        }

        return '';
    }, 1);

    try {
        $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);
        $result = Hibla\await(Hibla\async($callback));
        ob_end_flush();

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

    // Task finished cleanly — clear crash context
    $isProcessing = false;
    $currentTaskId = null;

    Hibla\Parallel\Internals\WorkerContext::setCurrentTaskId(null);

    gc_collect_cycles();
    write_frame(['status' => 'READY']);
}

exit(0);
