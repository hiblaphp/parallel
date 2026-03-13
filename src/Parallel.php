<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Interfaces\ParallelExecutorInterface;
use Hibla\Parallel\Interfaces\ProcessPoolInterface;

/**
 * Static facade and entry point for all parallel execution strategies.
 *
 * @example
 * ```php
 * // One-off parallel task
 * $result = await(
 *     Parallel::task()
 *         ->withTimeout(30)
 *         ->withMemoryLimit('256M')
 *         ->run(fn() => heavyWork())
 * );
 *
 * // Persistent worker pool
 * $pool = Parallel::pool(size: 4)
 *     ->withTimeout(30)
 *     ->withMemoryLimit('256M');
 *
 * $result = await($pool->run(fn() => heavyWork()));
 * $pool->shutdown();
 * ```
 */
final class Parallel
{
    private function __construct()
    {
    }

    /**
     * Create a fluent builder for a one-off parallel task or background process.
     * Each run() call spawns a fresh worker process.
     */
    public static function task(): ParallelExecutorInterface
    {
        return new ParallelExecutor();
    }

    /**
     * Create a fluent builder for a persistent worker pool.
     * Workers are spawned once and reused across multiple task submissions.
     *
     * @param int $size Number of persistent worker processes to maintain
     * @throws \InvalidArgumentException If size is less than 1
     */
    public static function pool(int $size): ProcessPoolInterface
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Pool size must be at least 1.');
        }

        return new ProcessPool($size);
    }
}
