<?php

namespace Hibla\Parallel\Process;

use Hibla\Parallel\Config\ConfigLoader;
use Hibla\Parallel\Utilities\BackgroundLogger;
use Hibla\Parallel\Serialization\CallbackSerializationManager;
use Hibla\Parallel\Serialization\SerializationException;
use Hibla\Parallel\Utilities\SystemUtilities;

/**
 * Manages the creation and execution of background processes
 */
class ProcessManager
{
    private ConfigLoader $config;
    private SystemUtilities $systemUtils;
    private BackgroundLogger $logger;

    public function __construct(
        ConfigLoader $config,
        SystemUtilities $systemUtils,
        BackgroundLogger $logger
    ) {
        $this->config = $config;
        $this->systemUtils = $systemUtils;
        $this->logger = $logger;
    }

    /**
     * Spawn a background task
     */
    public function spawnBackgroundTask(
        string $taskId,
        callable $callback,
        array $context,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): void {
        $taskFile = $this->systemUtils->getTempDirectory() . DIRECTORY_SEPARATOR . $taskId . '.php';
        $statusFile = $this->logger->getLogDirectory() . DIRECTORY_SEPARATOR . $taskId . '.status';
        $memoryLimit = $this->config->get('process.memory_limit', '512M');
        $timeout = (int) $this->config->get('process.timeout', 0);

        try {
            $this->createBackgroundTaskScript(
                $taskFile,
                $callback,
                $context,
                $taskId,
                $statusFile,
                $memoryLimit,
                $timeout,
                $frameworkInfo,
                $serializationManager
            );

            $this->spawnBackgroundProcess($taskFile);
        } catch (\Throwable $e) {
            if (file_exists($taskFile)) {
                unlink($taskFile);
            }
            throw $e;
        }
    }

    /**
     * Test background execution capabilities
     */
    public function testCapabilities(bool $verbose, CallbackSerializationManager $serializationManager): array
    {
        $results = [
            'success' => false,
            'errors' => [],
            'stats' => [],
        ];

        try {
            if ($verbose) {
                echo "🧪 Testing background execution capabilities...\n";
                echo "Environment: " . PHP_SAPI . " on " . PHP_OS_FAMILY . "\n";
            }

            // Test basic callback serialization
            $testCallback = function () {
                $testFile = sys_get_temp_dir() . '/defer_test_' . uniqid() . '.txt';
                file_put_contents($testFile, 'Background execution test: ' . date('Y-m-d H:i:s'));
                return $testFile;
            };

            if ($serializationManager->canSerializeCallback($testCallback)) {
                if ($verbose) echo "✅ Callback serialization: OK\n";
                $results['stats']['callback_serialization'] = true;
            } else {
                $results['errors'][] = 'Callback serialization failed';
                if ($verbose) echo "❌ Callback serialization: FAILED\n";
                return $results;
            }

            // Test context serialization
            $testContext = ['test' => true, 'timestamp' => time(), 'data' => ['nested' => 'value']];
            if ($serializationManager->canSerializeContext($testContext)) {
                if ($verbose) echo "✅ Context serialization: OK\n";
                $results['stats']['context_serialization'] = true;
            } else {
                $results['errors'][] = 'Context serialization failed';
                if ($verbose) echo "❌ Context serialization: FAILED\n";
            }

            $results['success'] = empty($results['errors']);
            $results['stats']['php_binary'] = $this->systemUtils->getPhpBinary();
            $results['stats']['temp_dir'] = $this->systemUtils->getTempDirectory();
            $results['stats']['log_dir'] = $this->logger->getLogDirectory();
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            if ($verbose) echo "❌ Test failed: " . $e->getMessage() . "\n";
        }

        return $results;
    }

    /**
     * Create a PHP script file for background execution
     */
    private function createBackgroundTaskScript(
        string $taskFile,
        callable $callback,
        array $context,
        string $taskId,
        string $statusFile,
        string $memoryLimit,
        int $timeout,
        array $frameworkInfo,
        CallbackSerializationManager $serializationManager
    ): void {
        try {
            $serializedCallback = $serializationManager->serializeCallback($callback);
            $serializedContext = $serializationManager->serializeContext($context);
        } catch (SerializationException $e) {
            throw new \RuntimeException('Failed to serialize callback or context: ' . $e->getMessage(), 0, $e);
        }

        $autoloadPath = $this->systemUtils->findAutoloadPath();
        $script = $this->generateBackgroundScript(
            $taskId,
            $serializedCallback,
            $serializedContext,
            $autoloadPath,
            $statusFile,
            $memoryLimit,
            $timeout,
            $frameworkInfo
        );

        if (file_put_contents($taskFile, $script, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to create background task file: {$taskFile}");
        }

        if ((fileperms($taskFile) & 0777) !== 0755) {
            chmod($taskFile, 0755);
        }
    }

    /**
     * Generate the background script template
     */
    /**
     * Generate the background script template with output capture
     */
    private function generateBackgroundScript(
        string $taskId,
        string $serializedCallback,
        string $serializedContext,
        string $autoloadPath,
        string $statusFile,
        string $memoryLimit,
        int $timeout,
        array $frameworkInfo
    ): string {
        $generatedAt = date('Y-m-d H:i:s');
        $frameworkName = $frameworkInfo['name'] ?? 'none';
        $frameworkBootstrap = $frameworkInfo['bootstrap_file'] ?? '';
        $frameworkInitCode = $frameworkInfo['init_code'] ?? '';
        $escapedBootstrapFile = addslashes($frameworkBootstrap);

        $loggingEnabled = $this->config->get('logging.enabled', true);
        $logDirectory = $this->config->get('logging.directory', null);
        $loggingEnabledStr = $loggingEnabled ? 'true' : 'false';
        $logDirectoryStr = $logDirectory ? "'" . addslashes($logDirectory) . "'" : 'null';

        return <<<PHP
<?php
/**
 * Background task with automatic cleanup
 * Task ID: {$taskId}
 * Generated at: {$generatedAt}
 */

declare(strict_types=1);

// FORK BOMB PROTECTION
putenv('DEFER_BACKGROUND_PROCESS=1');
\$_ENV['DEFER_BACKGROUND_PROCESS'] = '1';

// Set execution environment
set_time_limit({$timeout});
error_reporting(E_ALL);
ini_set('memory_limit', '{$memoryLimit}');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

\$taskId = '{$taskId}';
\$statusFile = '{$statusFile}';
\$startTime = microtime(true);
\$pid = getmypid();
\$capturedOutput = '';
\$taskFile = __FILE__;

// Configuration from parent process (embedded at generation time)
\$LOGGING_ENABLED = {$loggingEnabledStr};
\$LOG_DIRECTORY = {$logDirectoryStr};

// Check if status file should be cleaned up after task completion
function shouldCleanupStatusFile() {
    global \$LOGGING_ENABLED, \$LOG_DIRECTORY;
    // Clean up status file if:
    // 1. Logging is disabled, OR
    // 2. Logging is enabled but no persistent directory is configured (using temp dir)
    return !\$LOGGING_ENABLED || empty(\$LOG_DIRECTORY);
}

// Cleanup function for immediate cleanup (errors, signals)
function performImmediateCleanup() {
    global \$taskFile, \$statusFile;
    
    // Always clean up task file
    for (\$attempt = 0; \$attempt < 5; \$attempt++) {
        if (!file_exists(\$taskFile)) {
            break;
        }
        
        if (@unlink(\$taskFile)) {
            break;
        }
        
        usleep(100000); // 100ms
        
        \$tempName = \$taskFile . '.delete.' . time() . '.' . \$attempt;
        if (@rename(\$taskFile, \$tempName)) {
            @unlink(\$tempName);
            break;
        }
    }
    
    // Only delete status file immediately on errors/signals if cleanup is needed
    if (shouldCleanupStatusFile() && file_exists(\$statusFile)) {
        @unlink(\$statusFile);
    }
}

// Delayed cleanup function for successful completion
function performDelayedCleanup() {
    global \$taskFile, \$statusFile;
    
    // Always clean up task file
    for (\$attempt = 0; \$attempt < 5; \$attempt++) {
        if (!file_exists(\$taskFile)) {
            break;
        }
        
        if (@unlink(\$taskFile)) {
            break;
        }
        
        usleep(10000); // 10ms
        
        \$tempName = \$taskFile . '.delete.' . time() . '.' . \$attempt;
        if (@rename(\$taskFile, \$tempName)) {
            @unlink(\$tempName);
            break;
        }
    }
    
    // For successful completion, wait a bit longer before cleanup to allow status file reading
    if (shouldCleanupStatusFile() && file_exists(\$statusFile)) {
        // Wait 200ms to ensure any awaiting process can read the status file
        usleep(200000); // 200ms
        @unlink(\$statusFile);
    }
}

// Register cleanup for graceful shutdown
register_shutdown_function('performDelayedCleanup');

// Handle signals for immediate cleanup
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() { performImmediateCleanup(); exit(0); });
    pcntl_signal(SIGINT, function() { performImmediateCleanup(); exit(0); });
}

function updateTaskStatus(\$status, \$message = '', \$extra = []) {
    global \$taskId, \$statusFile, \$startTime, \$pid, \$capturedOutput;
    
    \$statusData = array_merge([
        'task_id' => \$taskId,
        'status' => \$status,
        'message' => \$message,
        'timestamp' => time(),
        'duration' => microtime(true) - \$startTime,
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'pid' => \$pid,
        'created_at' => '{$generatedAt}',
        'updated_at' => date('Y-m-d H:i:s')
    ], \$extra);
    
    if (!empty(\$capturedOutput)) {
        \$statusData['output'] = \$capturedOutput;
    }
    
    file_put_contents(\$statusFile, json_encode(\$statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function logError(\$e) {
    global \$capturedOutput;
    
    \$errorInfo = [
        'error_message' => \$e->getMessage(),
        'error_file' => \$e->getFile(),
        'error_line' => \$e->getLine(),
        'error_code' => \$e->getCode(),
        'stack_trace' => \$e->getTraceAsString()
    ];
    
    if (!empty(\$capturedOutput)) {
        \$errorInfo['output'] = \$capturedOutput;
    }
    
    updateTaskStatus('ERROR', 'Task failed: ' . \$e->getMessage(), \$errorInfo);
}

function captureOutput(\$buffer) {
    global \$capturedOutput;
    \$capturedOutput .= \$buffer;
    return \$buffer;
}

try {
    updateTaskStatus('RUNNING', 'Task started execution');
    
    if (file_exists('{$autoloadPath}')) {
        require_once '{$autoloadPath}';
        updateTaskStatus('RUNNING', 'Autoloader loaded successfully');
    } else {
        throw new RuntimeException('Autoloader not found at: {$autoloadPath}');
    }

    if ('{$frameworkName}' !== 'none' && '{$escapedBootstrapFile}' !== '') {
        \$bootstrapFile = '{$escapedBootstrapFile}';
        if (file_exists(\$bootstrapFile)) {
            try {
                {$frameworkInitCode}
                updateTaskStatus('RUNNING', '{$frameworkName} framework bootstrap loaded successfully');
            } catch (Throwable \$bootstrapError) {
                updateTaskStatus('RUNNING', 'Framework bootstrap failed, continuing: ' . \$bootstrapError->getMessage());
            }
        }
    }
    
    \$context = {$serializedContext};
    \$callback = {$serializedCallback};
    
    if (!is_callable(\$callback)) {
        throw new RuntimeException('Deserialized callback is not callable');
    }
    
    ob_start('captureOutput');
    updateTaskStatus('RUNNING', 'Executing callback');
    
    try {
        \$reflection = new ReflectionFunction(\$callback instanceof Closure ? \$callback : Closure::fromCallable(\$callback));
        \$paramCount = \$reflection->getNumberOfParameters();
        
        \$result = \$paramCount > 0 && !empty(\$context) ? \$callback(\$context) : \$callback();
        ob_end_flush();
        
    } catch (Throwable \$callbackError) {
        ob_end_flush();
        throw \$callbackError;
    }
    
    \$duration = microtime(true) - \$startTime;
    \$resultInfo = [
        'execution_time' => \$duration,
        'memory_final' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true)
    ];
    
    if (\$result !== null) {
        \$resultInfo['result'] = \$result;
        \$resultInfo['result_type'] = gettype(\$result);
        
        if (is_string(\$result) && strlen(\$result) > 1000) {
            \$resultInfo['result_truncated'] = true;
            \$resultInfo['result'] = substr(\$result, 0, 1000) . '... (truncated)';
            \$resultInfo['result_length'] = strlen(\$result);
        } elseif (is_array(\$result) && count(\$result) > 50) {
            \$resultInfo['result_truncated'] = true;
            \$resultInfo['result'] = array_slice(\$result, 0, 50);
            \$resultInfo['result_count'] = count(\$result);
        }
    }
    
    if (!empty(\$capturedOutput)) {
        \$resultInfo['output_length'] = strlen(\$capturedOutput);
        \$resultInfo['output_lines'] = substr_count(\$capturedOutput, "\n") + 1;
        
        if (strlen(\$capturedOutput) > 2000) {
            \$resultInfo['output_truncated'] = true;
            \$resultInfo['full_output'] = \$capturedOutput;
        }
    }
    
    updateTaskStatus('COMPLETED', "Task completed successfully in " . number_format(\$duration, 3) . " seconds", \$resultInfo);
    
} catch (Throwable \$e) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    logError(\$e);
    performImmediateCleanup();
    exit(1);
} finally {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Note: performDelayedCleanup() is called via register_shutdown_function
    // This ensures proper cleanup timing even if the script exits normally
}

exit(0);
PHP;
    }
    /**
     * Spawn background process with platform-specific handling
     */
    private function spawnBackgroundProcess(string $taskFile): void
    {
        $phpBinary = $this->systemUtils->getPhpBinary();
        $taskId = basename($taskFile, '.php');

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = "start /B \"\" \"{$phpBinary}\" \"{$taskFile}\" >nul 2>nul";
            $process = popen($cmd, 'r');
            if ($process === false) {
                throw new \RuntimeException("Failed to spawn Windows background process for task: {$taskId}");
            }
            pclose($process);
        } else {
            $opcacheFlags = '-d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M -d opcache.jit=tracing';

            $cmd = "\"{$phpBinary}\" {$opcacheFlags} \"{$taskFile}\" > /dev/null 2>&1 &";
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException("Failed to spawn Unix background process for task: {$taskId}, return code: {$returnCode}");
            }
        }
    }
}
