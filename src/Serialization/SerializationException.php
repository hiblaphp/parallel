<?php

namespace Hibla\Parallel\Serialization;

/**
 * Exception thrown when serialization fails
 */
class SerializationException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}