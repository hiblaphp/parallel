<?php

declare(strict_types=1);

namespace Hibla\Parallel\Exceptions;

/**
 * Thrown when the pool's respawn rate limit is exceeded, indicating a crash loop.
 *
 * This exception causes a hard pool shutdown and is used to reject all pending
 * and queued tasks when more workers restart within a one-second sliding window
 * than the configured threshold allows.
 *
 * @see \Hibla\Parallel\Interfaces\ProcessPoolInterface::withMaxRestartPerSecond()
 */
final class RespawnRateLimitException extends \RuntimeException
{
}
