<?php

namespace Hibla\Parallel\Serialization\Serializers;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

/**
 * Serializes instance method callbacks
 */
class InstanceMethodSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        if (!\is_array($callback) || \count($callback) !== 2) {
            return false;
        }

        [$object, $method] = $callback;

        return \is_object($object) &&
            \is_string($method) &&
            method_exists($object, $method);
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize instance method callback - requires opis/closure');
        }

        [$object, $method] = $callback;

        try {
            $serializedObject = serialize($object);
            return \sprintf(
                '[unserialize(%s), %s]',
                var_export($serializedObject, true),
                var_export($method, true)
            );
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize object instance: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 70;
    }
}
