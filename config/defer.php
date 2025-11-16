<?php

/**
 * Defer Library Configuration
 *
 * This file allows you to configure the behavior of the Defer background processing system.
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
    | .env variable: DEFER_LOGGING_ENABLED (true|false)
    | .env variable: DEFER_LOG_DIRECTORY (/path/to/your/logs)
    |
    */
    'logging' => [
        'enabled'   => ($_ENV['DEFER_LOGGING_ENABLED'] ?? 'true') === 'true',
        'directory' => $_ENV['DEFER_LOG_DIRECTORY'] ?? null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary Directory
    |--------------------------------------------------------------------------
    |
    | The directory to store the temporary PHP scripts for background tasks.
    | If null, the system's default temporary directory will be used.
    |
    | .env variable: DEFER_TEMP_DIRECTORY (/path/to/temp)
    |
    */
    'temp_directory' => $_ENV['DEFER_TEMP_DIRECTORY'] ?? null,

    /*
    |--------------------------------------------------------------------------
    | Background Process Settings
    |--------------------------------------------------------------------------
    |
    | 'memory_limit': The memory limit for each background process (e.g., '256M').
    | 'timeout':      The maximum execution time in seconds. Use 0 for no limit.
    |
    | .env variable: DEFER_MEMORY_LIMIT
    | .env variable: DEFER_TIMEOUT
    |
    */
    'process' => [
        'memory_limit' => $_ENV['DEFER_MEMORY_LIMIT'] ?? '512M',
        'timeout'      => (int) ($_ENV['DEFER_TIMEOUT'] ?? 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework Integration
    |--------------------------------------------------------------------------
    |
    | 'bootstrap_framework': Automatically detect and bootstrap a framework
    |                        like Laravel or Symfony. This makes your application's
    |                        services (e.g., database, models) available in the
    |                        background task. Set to false if you are running
    |                        in a pure PHP environment or want to handle
    |                        bootstrapping manually.
    |
    | .env variable: DEFER_BOOTSTRAP_FRAMEWORK (true|false)
    |
    */
    'bootstrap_framework' => ($_ENV['DEFER_BOOTSTRAP_FRAMEWORK'] ?? 'true') === 'true',

];