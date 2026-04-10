<?php

declare(strict_types=1);

namespace Tests\Fixtures;

class CallableTestInvokable
{
    public function __invoke(): string
    {
        return 'invokable-class';
    }
}