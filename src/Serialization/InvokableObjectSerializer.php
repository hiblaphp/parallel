<?php

namespace Hibla\Parallel\Serialization;

/**
 * Serializes invokable objects
 */
class InvokableObjectSerializer implements CallbackSerializerInterface
{
    private bool $serializationAvailable;

    public function __construct()
    {
        $this->serializationAvailable = function_exists('Opis\\Closure\\serialize');;
    }

    public function canSerialize(callable $callback): bool
    {
        return is_object($callback) &&
            method_exists($callback, '__invoke') &&
            $this->serializationAvailable;
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize invokable object - requires opis/closure');
        }

        try {
            $serialized = serialize($callback);
            return sprintf('unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize invokable object: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 60; // Medium priority
    }
}
