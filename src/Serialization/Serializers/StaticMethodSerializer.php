<?php

namespace Hibla\Parallel\Serialization\Serializers;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

/**
 * Serializes static method callbacks
 */
class StaticMethodSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        if (!\is_array($callback) || \count($callback) !== 2) {
            return false;
        }

        [$class, $method] = $callback;
        
        return \is_string($class) && 
               \is_string($method) && 
               class_exists($class) && 
               method_exists($class, $method);
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize invalid static method callback');
        }

        [$class, $method] = $callback;
        
        return "[{$class}::class, '{$method}']";
    }

    public function getPriority(): int
    {
        return 90;
    }
}