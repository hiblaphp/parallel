<?php

namespace Hibla\Parallel\Utilities;

use Hibla\Parallel\Config\ConfigLoader;

/**
 * System utilities and helper functions
 */
class SystemUtilities
{
    private ConfigLoader $config;
    private string $tempDir;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
        $tempDir = $this->config->get('temp_directory');
        $this->tempDir = $tempDir ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'defer_tasks');
        $this->ensureDirectories();
    }

    /**
     * Generate unique task ID with timestamp
     */
    public function generateTaskId(): string
    {
        return 'defer_' . date('Ymd_His') . '_' . uniqid('', true);
    }

    /**
     * Get PHP binary path with enhanced detection
     */
    public function getPhpBinary(): string
    {
        if (defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
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
                return realpath($path);
            }
        }


        return 'vendor/autoload.php';
    }

    /**
     * Detect framework and return bootstrap information
     */
    public function detectFramework(): array
    {
        $frameworks = [
            'laravel' => [
                'bootstrap_files' => ['bootstrap/app.php', '../bootstrap/app.php'],
                'detector_files' => ['artisan', 'app/Http/Kernel.php'],
                'init_code' => '$app = require $bootstrapFile; $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap();'
            ],
            'symfony' => [
                'bootstrap_files' => ['config/bootstrap.php', '../config/bootstrap.php'],
                'detector_files' => ['bin/console', 'symfony.lock'],
                'init_code' => 'require $bootstrapFile;'
            ],
        ];

        foreach ($frameworks as $name => $config) {
            foreach ($config['detector_files'] as $detectorFile) {
                if (file_exists($detectorFile) || file_exists('../' . $detectorFile)) {
                    $bootstrapFile = $this->findFrameworkBootstrap($config['bootstrap_files']);
                    if ($bootstrapFile) {
                        return [
                            'name' => $name,
                            'bootstrap_file' => $bootstrapFile,
                            'init_code' => $config['init_code']
                        ];
                    }
                }
            }
        }

        return ['name' => 'none', 'bootstrap_file' => null, 'init_code' => ''];
    }

    /**
     * Get environment information
     */
    public function getEnvironmentInfo(): array
    {
        return [
            'os_family' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * Get disk usage information
     */
    public function getDiskUsage(): array
    {
        $tempDirSize = $this->getDirectorySize($this->tempDir);

        return [
            'temp_dir_size' => $tempDirSize,
            'temp_dir_files' => count(glob($this->tempDir . DIRECTORY_SEPARATOR . '*')),
        ];
    }

    /**
     * Get temporary directory path
     */
    public function getTempDirectory(): string
    {
        return $this->tempDir;
    }

    /**
     * Calculate directory size recursively
     */
    private function getDirectorySize(string $directory): int
    {
        $size = 0;
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT) as $file) {
                $size += is_file($file) ? filesize($file) : $this->getDirectorySize($file);
            }
        }
        return $size;
    }

    /**
     * Find framework bootstrap file from possible paths
     */
    private function findFrameworkBootstrap(array $possibleFiles): ?string
    {
        $basePaths = [getcwd(), dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)];

        foreach ($basePaths as $basePath) {
            foreach ($possibleFiles as $file) {
                $fullPath = $basePath . '/' . $file;
                if (file_exists($fullPath)) {
                    return realpath($fullPath);
                }
            }
        }

        return null;
    }

    /**
     * Ensure necessary directories exist
     */
    private function ensureDirectories(): void
    {
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }
}
