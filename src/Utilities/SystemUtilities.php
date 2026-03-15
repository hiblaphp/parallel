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
     * Get PHP binary path with enhanced detection.
     *
     * Result is cached after the first call — PHP binary does not change
     * during process lifetime so repeated calls are free.
     *
     * @return string Path to PHP binary executable
     */
    public function getPhpBinary(): string
    {
        /** @var string|null $cached */
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        if (\defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            $cached = PHP_BINARY;

            return $cached;
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
                $cached = $path;

                return $cached;
            }

            $which      = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result     = shell_exec("{$which} {$path} 2>{$nullDevice}");

            if ($result !== null && \is_string($result) && trim($result) !== '') {
                $foundPath = trim($result);
                if (is_executable($foundPath)) {
                    $cached = $foundPath;

                    return $cached;
                }
            }
        }

        $cached = 'php';

        return $cached;
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
     * Get the number of available CPU cores.
     *
     * Result is cached after the first call — CPU count does not change
     * during process lifetime so repeated shell calls are avoided.
     *
     * Cross-platform detection order:
     *   1. shell_exec with platform-specific command (wmic / sysctl / nproc)
     *   2. Linux filesystem fallback (/sys/devices/system/cpu/present, /proc/cpuinfo)
     *   3. Windows environment variable (NUMBER_OF_PROCESSORS)
     *   4. Safe default of 4
     *
     * @return int<1, max> Number of logical CPU cores, minimum 1
     */
    public function getCpuCount(): int
    {
        /** @var int<1, max>|null $cached */
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        if (\function_exists('shell_exec')) {
            $command = match (PHP_OS_FAMILY) {
                'Windows' => 'wmic cpu get NumberOfLogicalProcessors /value',
                'Darwin'  => 'sysctl -n hw.logicalcpu',
                default   => 'nproc',
            };

            $output = @shell_exec($command);

            if (\is_string($output) && trim($output) !== '') {
                if (PHP_OS_FAMILY === 'Windows' && preg_match('/NumberOfLogicalProcessors=(\d+)/', $output, $m) === 1) {
                    /** @var int<1, max> $count */
                    $count  = max(1, (int) $m[1]);
                    $cached = $count;

                    return $cached;
                } elseif (($count = (int) trim($output)) > 0) {
                    /** @var int<1, max> $count */
                    $count  = max(1, $count);
                    $cached = $count;

                    return $cached;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            if (is_readable('/sys/devices/system/cpu/present')) {
                $content = trim((string) file_get_contents('/sys/devices/system/cpu/present'));
                if (preg_match('/^(\d+)-(\d+)$/', $content, $m) === 1) {
                    /** @var int<1, max> $count */
                    $count  = max(1, (int) $m[2] - (int) $m[1] + 1);
                    $cached = $count;

                    return $cached;
                }
            }

            if (is_readable('/proc/cpuinfo')) {
                $count = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor");
                if ($count > 0) {
                    /** @var int<2, max> $result */
                    $result = $count + 1;
                    $cached = $result;

                    return $cached;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $env = getenv('NUMBER_OF_PROCESSORS');
            if ($env !== false && (int) $env > 0) {
                /** @var int<1, max> $count */
                $count  = max(1, (int) $env);
                $cached = $count;

                return $cached;
            }
        }

        $cached = 4;

        return $cached;
    }
}
