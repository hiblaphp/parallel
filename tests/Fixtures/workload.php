<?php

declare(strict_types=1);

function cpuWorkload(int $iterations): int
{
    $sum = 0;
    for ($i = 0; $i < $iterations; $i++) {
        $sum += $i % 7;
    }

    return $sum;
}

function ioWorkload(int $microseconds): void
{
    usleep($microseconds);
}

function getBenchmarkConfig(): array
{
    $cpuCount = 4;
    if (PHP_OS_FAMILY === 'Windows') {
        $env = getenv('NUMBER_OF_PROCESSORS');
        if ($env !== false) {
            $cpuCount = (int) $env;
        }
    } else {
        $nproc = shell_exec('nproc 2>/dev/null');
        if ($nproc !== null) {
            $cpuCount = (int) $nproc;
        } else {
            $sysctl = shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($sysctl !== null) {
                $cpuCount = (int) $sysctl;
            }
        }
    }

    return [
        'poolSize' => max(1, $cpuCount),
        'cpu' => [
            'totalIterations' => 1_000_000_000,
            'taskCount' => 4,
        ],
        'io' => [
            'taskCount' => 100,
            'sleepUs' => 20_000, // 20ms per task
        ],
    ];
}
