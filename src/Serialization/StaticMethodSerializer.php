<?php

namespace Hibla\Parallel\Serialization;

/**
 * Serializes static method callbacks
 */
class StaticMethodSerializer implements CallbackSerializerInterface
{
    public function canSerialize(callable $callback): bool
    {
        if (!is_array($callback) || count($callback) !== 2) {
            return false;
        }

        [$class, $method] = $callback;
        
        return is_string($class) && 
               is_string($method) && 
               class_exists($class) && 
               method_exists($class, $method);
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize invalid static method callback');
        }

        return var_export($callback, true);
    }

    public function getPriority(): int
    {
        return 90; // High priority - reliable
    }
}