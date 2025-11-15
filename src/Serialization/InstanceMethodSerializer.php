<?php

namespace Hibla\Parallel\Serialization;

/**
 * Serializes instance method callbacks
 */
class InstanceMethodSerializer implements CallbackSerializerInterface
{
    private bool $serializationAvailable;

    public function __construct()
    {
        $this->serializationAvailable = class_exists('Opis\\Closure\\SerializableClosure');
    }

    public function canSerialize(callable $callback): bool
    {
        if (!$this->serializationAvailable) {
            return false;
        }

        if (!is_array($callback) || count($callback) !== 2) {
            return false;
        }

        [$object, $method] = $callback;

        return is_object($object) &&
            is_string($method) &&
            method_exists($object, $method);
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize instance method callback - requires opis/closure');
        }

        [$object, $method] = $callback;

        try {
            $serializedObject = serialize($object);
            return sprintf(
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
