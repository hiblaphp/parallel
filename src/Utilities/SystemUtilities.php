<?php

declare(strict_types=1);

namespace Hibla\Parallel\Utilities;

use Rcalicdan\ConfigLoader\Config;

use function Rcalicdan\ConfigLoader\configRoot;

/**
 * System utilities and helper functions
 */
class SystemUtilities
{
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

            if ($result !== null && \is_string($result) && trim($result) !== '') {
                $foundPath = trim($result);
                if (is_executable($foundPath)) {
                    return $foundPath;
                }
            }
        }

        return 'php';
    }

    /**
     * Find the autoload path from the root path
     *
     * @return string Path to composer autoload.php file
     */
    public function findAutoloadPath(): string
    {
        $rootDir = Config::getRootPath();

        return $rootDir . '/vendor/autoload.php';
    }

    /**
     * Get framework bootstrap information from config
     *
     * @return array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}
     */
    public function getFrameworkBootstrap(): array
    {
        $bootstrap = configRoot('hibla_parallel', 'bootstrap', null);

        if (! \is_array($bootstrap) || \count($bootstrap) === 0) {
            return [
                'name' => 'none',
                'bootstrap_file' => null,
                'bootstrap_callback' => null,
            ];
        }

        $bootstrapFile = $bootstrap['file'] ?? null;
        $bootstrapCallback = $bootstrap['callback'] ?? null;

        if ($bootstrapFile !== null && ! \is_string($bootstrapFile)) {
            throw new \RuntimeException(
                'Bootstrap file must be a string'
            );
        }

        if (\is_string($bootstrapFile) && $bootstrapFile !== '' && ! file_exists($bootstrapFile)) {
            throw new \RuntimeException(
                "Bootstrap file not found: {$bootstrapFile}"
            );
        }

        if ($bootstrapCallback !== null && ! is_callable($bootstrapCallback)) {
            throw new \RuntimeException(
                'Bootstrap callback must be callable'
            );
        }

        $finalBootstrapFile = null;
        if (\is_string($bootstrapFile) && $bootstrapFile !== '') {
            $realPath = realpath($bootstrapFile);
            $finalBootstrapFile = $realPath !== false ? $realPath : $bootstrapFile;
        }

        return [
            'name' => 'custom',
            'bootstrap_file' => $finalBootstrapFile,
            'bootstrap_callback' => $bootstrapCallback,
        ];
    }

    /**
     * Get the number of available CPU cores
     * Handle cross-platform CPU core detection
     *
     * @return int Number of CPU cores
     */
    public function getCpuCount(): int
    {
        if (\function_exists('shell_exec')) {
            $command = match (PHP_OS_FAMILY) {
                'Windows' => 'wmic cpu get NumberOfLogicalProcessors /value',
                'Darwin'  => 'sysctl -n hw.logicalcpu',
                default   => 'nproc',
            };

            $output = @shell_exec($command);

            if (\is_string($output) && trim($output) !== '') {
                if (PHP_OS_FAMILY === 'Windows' && preg_match('/NumberOfLogicalProcessors=(\d+)/', $output, $m) === 1) {
                    return (int) $m[1];
                } elseif (($count = (int) trim($output)) > 0) {
                    return $count;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            if (is_readable('/sys/devices/system/cpu/present')) {
                $content = trim((string) file_get_contents('/sys/devices/system/cpu/present'));
                if (preg_match('/^(\d+)-(\d+)$/', $content, $m) === 1) {
                    return (int) $m[2] - (int) $m[1] + 1;
                }
            }

            if (is_readable('/proc/cpuinfo')) {
                $count = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor");
                return $count > 0 ? $count + 1 : 1;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $env = getenv('NUMBER_OF_PROCESSORS');
            if ($env !== false) {
                return (int) $env;
            }
        }

        return 4;
    }
}
