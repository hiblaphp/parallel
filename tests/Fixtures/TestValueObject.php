<?php

declare(strict_types=1);

namespace Tests\Fixtures;

class TestValueObject
{
    public function __construct(
        public readonly string $id,
        private string $secret,
        public array $metadata = [],
        protected ?self $child = null
    ) {
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getChild(): ?self
    {
        return $this->child;
    }
    
    public function setChild(self $child): void
    {
        $this->child = $child;
    }
}