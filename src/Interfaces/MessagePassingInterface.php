<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

use Hibla\Parallel\ValueObjects\WorkerMessage;

/**
 * Defines the contract for execution strategies that support worker-to-parent
 * message passing via emit().
 */
interface MessagePassingInterface
{
    /**
     * Returns a new instance with a pool-level message handler registered.
     *
     * The handler is invoked for every MESSAGE frame emitted by any worker
     * via emit(). Intended for cross-cutting concerns such as logging,
     * metrics, or progress tracking that apply to all tasks equally.
     *
     * The handler is wrapped in async() on invocation — it is safe to use
     * await() inside the handler without blocking the read loop fiber.
     *
     * For task-specific handling, pass a handler directly to run() instead.
     * When both are set, the per-task handler is scheduled first, followed
     * by this handler — both run concurrently with no completion ordering
     * guarantees between them.
     *
     * @param callable(WorkerMessage): void $handler
     *
     * @return static A new instance with the handler registered.
     */
    public function onMessage(callable $handler): static;
}
