<?php

declare(strict_types=1);

namespace Hibla\Parallel\Interfaces;

/**
 * Fluent configuration methods shared by all executor types.
 */
interface ExecutorConfigInterface
{
    public function withTimeout(int $seconds): static;

    public function withoutTimeout(): static;

    public function withMemoryLimit(string $limit): static;

    public function withUnlimitedMemory(): static;

    public function withBootstrap(string $file, ?callable $callback = null): static;

    public function withMaxNestingLevel(int $level): static;
}
