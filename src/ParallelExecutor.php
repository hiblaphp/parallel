<?php

declare(strict_types=1);

namespace Hibla\Parallel;

use Hibla\Parallel\Interfaces\NonPersistentExecutorInterface;
use Hibla\Parallel\Interfaces\PersistentPoolExecutorInterface;
use Hibla\Parallel\Internals\NonPersistentExecutor;
use Hibla\Parallel\Internals\PersistentPoolExecutor;

/**
 * Static facade and entry point for all parallel execution strategies.
 *
 * @example
 * ```php
 * // One-off parallel task
 * $result = await(
 *     ParallelExecutor::create()
 *         ->withTimeout(30)
 *         ->withMemoryLimit('256M')
 *         ->run(fn() => heavyWork())
 * );
 *
 * // Persistent worker pool
 * $pool = ParallelExecutor::createPersistentPool(size: 4)
 *     ->withTimeout(30)
 *     ->withMemoryLimit('256M');
 *
 * $result = await($pool->run(fn() => heavyWork()));
 * $pool->shutdown();
 * ```
 */
final class ParallelExecutor
{
    private function __construct()
    {
    }

    /**
     * Create a fluent builder for a one-off parallel task or background process.
     * Each run() call spawns a fresh worker process.
     */
    public static function create(): NonPersistentExecutorInterface
    {
        return new NonPersistentExecutor();
    }

    /**
     * Create a fluent builder for a persistent worker pool.
     * Workers are spawned once and reused across multiple task submissions.
     *
     * @param int $size Number of persistent worker processes to maintain
     * @throws \InvalidArgumentException If size is less than 1
     */
    public static function createPersistentPool(int $size): PersistentPoolExecutorInterface
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('Pool size must be at least 1.');
        }

        return new PersistentPoolExecutor($size);
    }
}
