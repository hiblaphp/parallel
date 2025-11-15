<?php

namespace Hibla\Parallel\Serialization;

/**
 * Serializes string function callbacks
 */
class StringFunctionSerializer implements CallbackSerializerInterface
{
    public function canSerialize(callable $callback): bool
    {
        return is_string($callback) && function_exists($callback);
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize non-string or non-existent function');
        }

        return var_export($callback, true);
    }

    public function getPriority(): int
    {
        return 100; // High priority - simple and reliable
    }
}
