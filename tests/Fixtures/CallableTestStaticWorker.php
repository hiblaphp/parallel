<?php

declare(strict_types=1);

namespace Tests\Fixtures;

class CallableTestStaticWorker
{
    public static function run(): string
    {
        return 'static-method';
    }

    public static function runWithArgs(string $prefix, int $value): string
    {
        return "{$prefix}-{$value}";
    }
}
