<?php

declare(strict_types=1);

namespace Hibla\Parallel\Exceptions;

/**
 * Thrown when the current PHP environment is missing required functions
 * or extensions needed for the library to operate.
 */
class EnvironmentException extends ParallelException
{
}
