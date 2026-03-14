<?php

declare(strict_types=1);

namespace Hibla\Parallel\ValueObjects;

/**
 * Represents a message emitted from a worker process to the parent.
 *
 * Constructed by the parent when a MESSAGE frame is received from a worker.
 * The data property transparently supports any serializable PHP value —
 * scalars, arrays, and objects all round-trip correctly across the process boundary.
 */
final readonly class WorkerMessage
{
    /**
     * @param mixed $data The data emitted by the worker via emit()
     * @param int $pid The PID of the worker process that emitted the message
     */
    public function __construct(
        public mixed $data,
        public int $pid,
    ) {
    }
}
