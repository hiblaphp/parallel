<?php

declare(strict_types=1);

namespace Tests\Fixtures;

function callable_test_named_function(): string
{
    return 'named-function';
}

function callable_test_named_function_with_args(string $prefix, int $value): string
{
    return "{$prefix}-{$value}";
}