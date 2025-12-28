<?php

use function Rcalicdan\ConfigLoader\env;

require __DIR__ . '/vendor/autoload.php';

/**
 * Hibla Parallel Library Configuration
 *
 * This file allows you to configure the behavior of the Hibla Parallel background processing system.
 * It reads values directly from environment variables loaded from your project's .env file.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | 'enabled':   Controls whether detailed logs are written for each task.
    |              Set to `false` in production for better performance if you
    |              don't need detailed per-task logs.
    |
    | 'directory': The absolute path to store logs and status files.
    |              If null, a system temporary directory will be used. It is
    |              highly recommended to set a persistent path for production.
    |
    | .env variable: HIBLA_PARALLEL_LOGGING_ENABLED (true|false)
    | .env variable: HIBLA_PARALLEL_LOG_DIRECTORY (/path/to/your/logs)
    |
    */
    'logging' => [
        'enabled'   => env('HIBLA_PARALLEL_LOGGING_ENABLED', false),
        'directory' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Process Settings
    |--------------------------------------------------------------------------
    |
    | 'memory_limit': The memory limit for each background process (e.g., '256M').
    |
    | .env variable: HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT
    */
    'background_process' => [
        'memory_limit' => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT', '512M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework Integration
    |--------------------------------------------------------------------------
    |
    | 'auto_detect': Automatically detect and bootstrap for Laravel/Symfony frameworks.
    |                Set to false if you want to use custom bootstrap config below.
    |
    | 'custom': Define your own bootstrap configuration for any framework/app.
    |           When configured, this takes precedence over auto-detection.
    |
    | Example for WordPress:
    | 'custom' => [
    |     'bootstrap_file' => __DIR__ . '/../wp-load.php',
    |     'init_code' => 'require $bootstrapFile; do_action("init");'
    | ]
    |
    | Example for custom app:
    | 'custom' => [
    |     'bootstrap_file' => __DIR__ . '/bootstrap/app.php',
    |     'init_code' => '$app = require $bootstrapFile; $app->boot();'
    | ]
    |
    | .env variable: HIBLA_PARALLEL_BOOTSTRAP_FRAMEWORK (true|false)
    |
    */
    'bootstrap_framework' => [
        'auto_detect' => env('HIBLA_PARALLEL_BOOTSTRAP_FRAMEWORK', true),
        
        // Custom bootstrap configuration (leave null to use auto-detection)
        'custom' => null,
        
        // Example configurations (uncomment and modify as needed):
        // 'custom' => [
        //     'bootstrap_file' => __DIR__ . '/bootstrap/app.php',
        //     'init_code' => '$app = require $bootstrapFile; $app->boot();'
        // ],
    ],
];