<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\BackgroundLogger;
use Rcalicdan\ConfigLoader\Config;

describe('BackgroundLogger', function () {
    $getTempDir = fn () => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_logger_test_' . uniqid();

    $cleanupDir = function (string $dir) {
        if (! is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $path = $dir . DIRECTORY_SEPARATOR . $file;
                is_dir($path) ? rmdir($path) : unlink($path);
            }
        }
        rmdir($dir);
    };

    afterEach(function () {
        Config::reset();
    });

    it('initializes with logging disabled by default', function () use ($getTempDir) {
        $logger = new BackgroundLogger();

        expect($logger->isDetailedLoggingEnabled())->toBeFalse();

        $defaultDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_parallel_logs';
        expect($logger->getLogDirectory())->toBe($defaultDir);
    });

    it('can enable logging via constructor arguments', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();

        $logger = new BackgroundLogger(enableDetailedLogging: true, customLogDir: $tempDir);

        expect($logger->isDetailedLoggingEnabled())->toBeTrue();
        expect($logger->getLogDirectory())->toBe($tempDir);
        expect(is_dir($tempDir))->toBeTrue();

        expect(file_exists($tempDir . DIRECTORY_SEPARATOR . 'background_tasks.log'))->toBeTrue();

        $cleanupDir($tempDir);
    });

    it('can enable logging via configuration', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();

        Config::setFromRoot('hibla_parallel', 'logging.enabled', true);
        Config::setFromRoot('hibla_parallel', 'logging.directory', $tempDir);

        $logger = new BackgroundLogger();

        expect($logger->isDetailedLoggingEnabled())->toBeTrue();
        expect($logger->getLogDirectory())->toBe($tempDir);

        $cleanupDir($tempDir);
    });

    it('prioritizes constructor arguments over configuration', function () use ($getTempDir, $cleanupDir) {
        $configDir = $getTempDir() . '_config';
        $argDir = $getTempDir() . '_arg';

        Config::setFromRoot('hibla_parallel', 'logging.enabled', true);
        Config::setFromRoot('hibla_parallel', 'logging.directory', $configDir);

        $logger = new BackgroundLogger(enableDetailedLogging: false, customLogDir: $argDir);

        expect($logger->isDetailedLoggingEnabled())->toBeFalse();

        expect($logger->getLogDirectory())->toBe($argDir);

        $cleanupDir($configDir);
        $cleanupDir($argDir);
    });

    it('creates the log directory if it does not exist', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();

        expect(is_dir($tempDir))->toBeFalse();

        new BackgroundLogger(enableDetailedLogging: true, customLogDir: $tempDir);

        expect(is_dir($tempDir))->toBeTrue();

        $cleanupDir($tempDir);
    });

    it('writes initialization log when enabled', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();
        $logger = new BackgroundLogger(enableDetailedLogging: true, customLogDir: $tempDir);

        $logFile = $tempDir . DIRECTORY_SEPARATOR . 'background_tasks.log';
        $content = file_get_contents($logFile);

        expect($content)->toContain('Background process executor initialized');
        expect($content)->toContain('[INFO] [SYSTEM]');

        $cleanupDir($tempDir);
    });

    it('writes formatted task event to file', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();
        $logger = new BackgroundLogger(enableDetailedLogging: true, customLogDir: $tempDir);

        $taskId = 'task_123';
        $message = 'Process started';
        $level = 'SPAWNED';

        $logger->logTaskEvent($taskId, $level, $message);

        $logFile = $tempDir . DIRECTORY_SEPARATOR . 'background_tasks.log';
        $content = file_get_contents($logFile);

        // Regex to match: [YYYY-MM-DD HH:MM:SS] [SPAWNED] [task_123] Process started
        $pattern = '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] \[' . $level . '\] \[' . $taskId . '\] ' . $message . '/';

        expect($content)->toMatch($pattern);

        $cleanupDir($tempDir);
    });

    it('does nothing when logging is disabled', function () use ($getTempDir, $cleanupDir) {
        $tempDir = $getTempDir();

        $logger = new BackgroundLogger(enableDetailedLogging: false, customLogDir: $tempDir);

        $logger->logTaskEvent('task_1', 'INFO', 'This should not be written');

        $logFile = $tempDir . DIRECTORY_SEPARATOR . 'background_tasks.log';

        expect(file_exists($logFile))->toBeFalse();

        $cleanupDir($tempDir);
    });
});
