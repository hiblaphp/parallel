<?php

declare(strict_types=1);

use function Rcalicdan\ConfigLoader\env;

require __DIR__ . '/vendor/autoload.php';

/**
 * Hibla Parallel Library Configuration
 *
 * This file allows you to configure the default behavior of the Hibla Parallel background processing system.
 */
return[
    /*
    |--------------------------------------------------------------------------
    | Maximum Nesting Level
    |--------------------------------------------------------------------------
    |
    | The maximum number of parallel() calls that can be nested.
    |
    | .env variable: HIBLA_PARALLEL_MAX_NESTING_LEVEL (int)
    */
    'max_nesting_level' => env('HIBLA_PARALLEL_MAX_NESTING_LEVEL', 5),

    /*
    |--------------------------------------------------------------------------
    | Standard Process Settings (Parallel Task & Pool)
    |--------------------------------------------------------------------------
    |
    | 'memory_limit': The memory limit for standard processes and pools.
    | 'timeout': The default maximum execution time in seconds.
    |
    */
    'process' => [
        'memory_limit' => env('HIBLA_PARALLEL_PROCESS_MEMORY_LIMIT', '512M'),
        'timeout' => env('HIBLA_PARALLEL_PROCESS_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Process Settings (Fire-and-forget)
    |--------------------------------------------------------------------------
    |
    | 'memory_limit': The memory limit for each background process.
    | 'timeout': The default maximum execution time in seconds.
    |
    | 'spawn_limit_per_second': Safety valve to prevent fork bombs.
    |                           Limits the number of background tasks spawned
    |                           per second. Default is 50.
    |
    */
    'background_process' => [
        'memory_limit' => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT', '512M'),
        'timeout' => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_TIMEOUT', 600),
        'spawn_limit_per_second' => env('HIBLA_PARALLEL_BACKGROUND_SPAWN_LIMIT', 50, true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework Bootstrap Configuration
    |--------------------------------------------------------------------------
    |
    | Configure custom bootstrap for your application/framework.
    | If null, no framework bootstrap will be loaded (pure PHP mode).
    |
    | 'file': Path to the bootstrap file to require
    | 'callback': A callable that will be executed after the file is required.
    |             The callback receives the bootstrap file path as parameter.
    |
    | Laravel Example:
    | 'bootstrap' =>[
    |     'file' => __DIR__ . '/bootstrap/app.php',
    |     'callback' => function(string $bootstrapFile) {
    |         $app = require $bootstrapFile;
    |         $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    |         $kernel->bootstrap();
    |         return $app;
    |     }
    | ]
    |
    | Symfony Example:
    | 'bootstrap' =>[
    |     'file' => __DIR__ . '/config/bootstrap.php',
    |     'callback' => function(string $bootstrapFile) {
    |         require $bootstrapFile;
    |     }
    | ]
    |
    */
    'bootstrap' => null,
];
