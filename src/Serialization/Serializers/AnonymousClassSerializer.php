<?php

namespace Hibla\Parallel\Serialization\Serializers;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

use function Opis\Closure\serialize;

/**
 * Serializes anonymous class instances
 */
class AnonymousClassSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        if (!\is_object($callback)) {
            return false;
        }

        $reflection = new \ReflectionClass($callback);
        return $reflection->isAnonymous();
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize non-anonymous class');
        }

        try {
            $serialized = serialize($callback);
            return \sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize anonymous class: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 70; // Medium-high priority, before invokable objects
    }
}