<?php

declare(strict_types=1);

namespace Tests\Fixtures;

class CallableTestInvokableWithArgs
{
    public function __invoke(string $prefix, int $value): string
    {
        return "{$prefix}-{$value}";
    }
}
