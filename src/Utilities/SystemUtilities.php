<?php

declare(strict_types=1);

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Exceptions\ParallelException;
use Rcalicdan\ConfigLoader\Config;

use function Rcalicdan\ConfigLoader\configRoot;

/**
 * System utilities and helper functions
 */
final class SystemUtilities
{
    private static ?string $phpBinary = null;

    /**
     * @var int<1, max>|null
     */
    private static ?int $cpuCount = null;

    /** @var array<string, string> */
    private static array $workerPathCache = [];

    /**
     * Prevent instantiation of this utility class.
     */
    private function __construct() {}

    /**
     * Get PHP binary path with enhanced detection.
     *
     * Result is cached after the first call — PHP binary does not change
     * during process lifetime so repeated calls are free.
     *
     * @return string Path to PHP binary executable
     */
    public static function getPhpBinary(): string
    {
        if (self::$phpBinary !== null) {
            return self::$phpBinary;
        }

        if (\defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            return self::$phpBinary = PHP_BINARY;
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
                return self::$phpBinary = $path;
            }

            $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
            $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
            $result = shell_exec("{$which} {$path} 2>{$nullDevice}");

            if ($result !== null && \is_string($result) && trim($result) !== '') {
                $foundPath = trim($result);
                if (is_executable($foundPath)) {
                    return self::$phpBinary = $foundPath;
                }
            }
        }

        return self::$phpBinary = 'php';
    }

    /**
     * Find the autoload path from the root path
     *
     * @return string Path to composer autoload.php file
     */
    public static function findAutoloadPath(): string
    {
        $rootDir = Config::getRootPath();

        return $rootDir . '/vendor/autoload.php';
    }

    /**
     * Get framework bootstrap information from config
     *
     * @return array{name: string, bootstrap_file: string|null, bootstrap_callback: callable|null}
     */
    public static function getFrameworkBootstrap(): array
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
     * @return int<1, max> Number of logical CPU cores, minimum 1
     */
    public static function getCpuCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        if (\function_exists('shell_exec')) {
            $command = match (PHP_OS_FAMILY) {
                'Windows' => 'wmic cpu get NumberOfLogicalProcessors /value',
                'Darwin' => 'sysctl -n hw.logicalcpu',
                default => 'nproc',
            };

            $output = @shell_exec($command);

            if (\is_string($output) && trim($output) !== '') {
                if (PHP_OS_FAMILY === 'Windows' && preg_match('/NumberOfLogicalProcessors=(\d+)/', $output, $m) === 1) {
                    return self::$cpuCount = max(1, (int) $m[1]);
                } elseif (($count = (int) trim($output)) > 0) {
                    return self::$cpuCount = max(1, $count);
                }
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            if (is_readable('/sys/devices/system/cpu/present')) {
                $content = trim((string) file_get_contents('/sys/devices/system/cpu/present'));
                if (preg_match('/^(\d+)-(\d+)$/', $content, $m) === 1) {
                    return self::$cpuCount = max(1, (int) $m[2] - (int) $m[1] + 1);
                }
            }

            if (is_readable('/proc/cpuinfo')) {
                $count = substr_count((string) file_get_contents('/proc/cpuinfo'), "\nprocessor");
                if ($count > 0) {
                    return self::$cpuCount = $count + 1;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $env = getenv('NUMBER_OF_PROCESSORS');
            if ($env !== false && (int) $env > 0) {
                return self::$cpuCount = max(1, (int) $env);
            }
        }

        return self::$cpuCount = 4;
    }

    /**
     * Resolves the full path to a worker script.
     *
     * Attempts to locate the worker script in various possible installation
     * locations, including Composer vendor directories and relative paths.
     * Result is cached after the first call per script name.
     *
     * @param string $scriptName Name of the worker script file (e.g., 'worker.php')
     *
     * @return string Absolute path to the worker script
     *
     * @throws ParallelException If the worker script cannot be found
     */
    public static function getWorkerPath(string $scriptName): string
    {
        if (isset(self::$workerPathCache[$scriptName])) {
            return self::$workerPathCache[$scriptName];
        }

        $localPaths = [
            dirname(__DIR__) . '/' . $scriptName,
            dirname(__DIR__) . '/../' . $scriptName,
        ];

        foreach ($localPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $resolvedPath = realpath($path);
                if ($resolvedPath !== false) {
                    return self::$workerPathCache[$scriptName] = $resolvedPath;
                }
            }
        }

        if (class_exists(\Composer\Autoload\ClassLoader::class, false)) {
            try {
                $reflector = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
                $fileName = $reflector->getFileName();

                if ($fileName !== false) {
                    $vendorPath = dirname($fileName, 2) . '/hiblaphp/parallel/src/' . $scriptName;
                    if (file_exists($vendorPath) && is_readable($vendorPath)) {
                        $resolvedPath = realpath($vendorPath);
                        if ($resolvedPath !== false) {
                            return self::$workerPathCache[$scriptName] = $resolvedPath;
                        }
                    }
                }
            } catch (\ReflectionException) {
                // Continue if reflection fails
            }
        }

        throw new ParallelException("Worker script '$scriptName' not found.");
    }
}
