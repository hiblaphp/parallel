<?php

namespace Hibla\Parallel\Serialization;

use function Opis\Closure\serialize as opisSerialize;

class ClosureSerializer implements CallbackSerializerInterface
{
    private bool $opisClosureAvailable;

    public function __construct()
    {
        $this->opisClosureAvailable = function_exists('Opis\\Closure\\serialize');
    }

    public function canSerialize(callable $callback): bool
    {
        return $callback instanceof \Closure && $this->opisClosureAvailable;
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Cannot serialize closure - requires opis/closure package');
        }

        try {
            $serialized = opisSerialize($callback);
            return sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize closure: ' . $e->getMessage(), $e);
        }
    }

    public function getPriority(): int
    {
        return 80;
    }
}
