<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class ProgressUpdate
{
    public function __construct(
        public readonly int $percent,
        public readonly string $label,
    ) {
    }
}
