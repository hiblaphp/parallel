<?php

namespace Hibla\Parallel\Config;

use Dotenv\Dotenv;

/**
 * A singleton configuration loader for the Defer library.
 *
 * It automatically finds the project root, loads .env variables,
 * and parses the `config/defer.php` file.
 */
final class ConfigLoader
{
    private static ?self $instance = null;
    private array $config = [];

    private function __construct()
    {
        $rootDir = $this->findProjectRoot();

        if ($rootDir) {
            if (file_exists($rootDir . '/.env')) {
                try {
                    $dotenv = Dotenv::createImmutable($rootDir);
                    $dotenv->load();
                } catch (\Throwable $e) {
                    // Fail silently if Dotenv is not installed or fails.
                    // This allows the library to work without it.
                }
            }

            $configFile = $rootDir . '/config/defer.php';
            if (file_exists($configFile)) {
                $this->config = require $configFile;
            }
        }
    }

    /**
     * Gets the singleton instance of the loader.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param string $key The key of the config value (e.g., 'process.timeout').
     * @param mixed $default The default value to return if the key is not found.
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (is_array($value) && isset($value[$segment])) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /**
     * Searches upwards from the current directory to find the project root.
     * The root is identified by the presence of a `vendor` directory.
     */
    private function findProjectRoot(): ?string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/vendor')) {
                return $dir;
            }
            $parentDir = dirname($dir);
            if ($parentDir === $dir) { 
                return null;
            }
            $dir = $parentDir;
        }
        return null;
    }
}