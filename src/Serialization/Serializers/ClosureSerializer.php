<?php

namespace Hibla\Parallel\Serialization\Serializers;

use function Opis\Closure\serialize;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

/**
 * Serializes closure callbacks using opis/closure
 */
class ClosureSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        return $callback instanceof \Closure;
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize non-closure callback');
        }

        try {
            $serialized = serialize($callback);
            return \sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize closure: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 80;
    }
}