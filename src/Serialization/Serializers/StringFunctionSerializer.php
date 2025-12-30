<?php

namespace Hibla\Parallel\Serialization\Serializers;

use Hibla\Parallel\Serialization\Exceptions\SerializationException;
use Hibla\Parallel\Serialization\Interfaces\CallbackSerializerInterface;

/**
 * Serializes string function callbacks
 */
class StringFunctionSerializer implements CallbackSerializerInterface
{
    public function canSerialize(mixed $callback): bool
    {
        return \is_string($callback) && function_exists($callback);
    }

    public function serialize(mixed $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize non-string function callback');
        }
        
        return "'" . $callback . "'";
    }

    public function getPriority(): int
    {
        return 100;
    }
}