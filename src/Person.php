<?php

declare(strict_types=1);

namespace Hibla\Parallel;

readonly class Person
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}
