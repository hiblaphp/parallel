<?php

namespace Hibla\Parallel\Serialization\Serializers;

use function Opis\Closure\serialize;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

/**
 * Serializes invokable objects using opis/closure
 */
class InvokableObjectSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        return \is_object($callback) && method_exists($callback, '__invoke');
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize non-invokable object');
        }

        try {
            $serialized = serialize($callback);
            return \sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize invokable object: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 60;
    }
}