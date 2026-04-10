<?php

declare(strict_types=1);

namespace Tests\Fixtures;

class CallableTestInstanceWorker
{
    public function __construct(private readonly string $tag = 'default') {}

    public function run(): string
    {
        return "instance-method-{$this->tag}";
    }

    public function runWithArgs(string $prefix, int $value): string
    {
        return "{$prefix}-{$value}-{$this->tag}";
    }
}