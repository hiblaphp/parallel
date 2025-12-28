<?php

namespace Hibla\Parallel\Utilities;

use function Rcalicdan\ConfigLoader\configRoot;

/**
 * System utilities and helper functions
 */
class SystemUtilities
{
    /**
     * Generate unique task ID with timestamp
     *
     * @return string Unique task identifier with format defer_YYYYMMDD_HHMMSS_uniqid
     */
    public function generateTaskId(): string
    {
        return 'defer_' . date('Ymd_His') . '_' . uniqid('', true);
    }

    /**
     * Get PHP binary path with enhanced detection
     *
     * @return string Path to PHP binary executable
     */
    public function getPhpBinary(): string
    {
        if (\defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        $possiblePaths = [
            'php',
            'php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/php/bin/php',
            'C:\\php\\php.exe',
            'C:\\Program Files\\PHP\\php.exe',
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path)) {
                return $path;
            }

            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result = shell_exec("{$which} {$path} 2>{$nullDevice}");

            if ($result && trim($result)) {
                $foundPath = trim($result);
                if (is_executable($foundPath)) {
                    return $foundPath;
                }
            }
        }

        return 'php';
    }

    /**
     * Find the autoload path with multiple fallback strategies
     *
     * @return string Path to composer autoload.php file
     */
    public function findAutoloadPath(): string
    {
        $possiblePaths = [
            getcwd() . '/vendor/autoload.php',
            getcwd() . '/../vendor/autoload.php',
            __DIR__ . '/../../../../vendor/autoload.php',
            __DIR__ . '/../../../vendor/autoload.php',
            __DIR__ . '/../../vendor/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/vendor/autoload.php',
            dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . '/../vendor/autoload.php',
            ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/../vendor/autoload.php',
        ];

        $possiblePaths = array_filter(array_unique($possiblePaths));

        foreach ($possiblePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return realpath($path) ?: $path;
            }
        }

        return 'vendor/autoload.php';
    }

    /**
     * Get framework bootstrap information from config
     *
     * @return array{name: string, bootstrap_file: string|null, init_code: string}
     */
    public function getFrameworkBootstrap(): array
    {
        $bootstrap = configRoot('hibla_parallel', 'bootstrap', null);

        if (!\is_array($bootstrap) || empty($bootstrap)) {
            return ['name' => 'none', 'bootstrap_file' => null, 'init_code' => ''];
        }

        $bootstrapFile = $bootstrap['file'] ?? null;
        $initCode = $bootstrap['init'] ?? '';

        if (!empty($bootstrapFile) && !file_exists($bootstrapFile)) {
            throw new \RuntimeException(
                "Bootstrap file not found: {$bootstrapFile}"
            );
        }

        return [
            'name' => 'custom',
            'bootstrap_file' => $bootstrapFile ? (realpath($bootstrapFile) ?: $bootstrapFile) : null,
            'init_code' => $initCode,
        ];
    }
}