<?php

namespace Hibla\Parallel\Serialization;

/**
 * Interface for callback serialization strategies
 */
interface CallbackSerializerInterface
{
    /**
     * Check if this serializer can handle the given callback
     *
     * @param callable $callback The callback to check
     * @return bool True if this serializer can handle the callback
     */
    public function canSerialize(callable $callback): bool;

    /**
     * Serialize a callback to PHP code string
     *
     * @param callable $callback The callback to serialize
     * @return string PHP code that recreates the callback
     * @throws SerializationException If serialization fails
     */
    public function serialize(callable $callback): string;

    /**
     * Get the priority of this serializer (higher = preferred)
     *
     * @return int Priority value
     */
    public function getPriority(): int;
}