<?php

/**
 * Hibla Parallel Fire-and-Forget Worker Script
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

$stdin = fopen('php://stdin', 'r');

stream_set_blocking($stdin, true);

$payload = fgets($stdin);
fclose($stdin);

if (empty($payload)) {
    exit(1);
}

try {
    $taskData = json_decode($payload, true);
    if (! $taskData) {
        exit(1);
    }

    $timeoutSeconds = $taskData['timeout_seconds'] ?? 60;
    $memoryLimit = $taskData['memory_limit'] ?? '512M';

    ini_set('memory_limit', $memoryLimit);
    ini_set('max_execution_time', (string)$timeoutSeconds);
    set_time_limit($timeoutSeconds);

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_alarm') && function_exists('pcntl_signal')) {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        pcntl_signal(SIGALRM, function () {
            exit(124);
        });

        pcntl_alarm($timeoutSeconds);
    }

    if (isset($taskData['autoload_path']) && file_exists($taskData['autoload_path'])) {
        require_once $taskData['autoload_path'];

        Hibla\Parallel\Internals\WorkerContext::markAsBackgroundWorker();
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

    $callback = $serializationManager->unserializeCallback($taskData['serialized_callback']);

    Hibla\await(Hibla\async($callback));

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
} catch (Throwable $e) {
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
}

exit(0);
