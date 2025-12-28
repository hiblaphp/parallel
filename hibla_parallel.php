<?php

use function Rcalicdan\ConfigLoader\env;

require __DIR__ . '/vendor/autoload.php';

/**
 * Hibla Parallel Library Configuration
 *
 * This file allows you to configure the behavior of the Hibla Parallel background processing system.
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
    |              If null, a system temporary directory will be used.
    |
    | .env variable: HIBLA_PARALLEL_LOGGING_ENABLED (true|false)
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
    | 'memory_limit': The memory limit for each background process (e.g., '512M').
    |
    | .env variable: HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT
    |
    */
    'background_process' => [
        'memory_limit' => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT', '512M'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework Bootstrap Configuration
    |--------------------------------------------------------------------------
    |
    | Configure custom bootstrap for your application/framework.
    | If null, no framework bootstrap will be loaded (pure PHP mode).
    |
    | Laravel Example:
    | 'bootstrap' => [
    |     'file' => __DIR__ . '/bootstrap/app.php',
    |     'init' => <<<'PHP'
    |         $app = require $bootstrapFile;
    |         $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    |         $kernel->bootstrap();
    |     PHP,
    | ]
    |
    | Symfony Example:
    | 'bootstrap' => [
    |     'file' => __DIR__ . '/config/bootstrap.php',
    |     'init' => <<<'PHP'
    |         require $bootstrapFile;
    |     PHP,
    | ]
    |
    | Custom App Example:
    | 'bootstrap' => [
    |     'file' => __DIR__ . '/bootstrap/worker.php',
    |     'init' => <<<'PHP'
    |         $app = require $bootstrapFile;
    |         $app->initialize();
    |     PHP,
    | ]
    |
    */
    'bootstrap' => null,
];
