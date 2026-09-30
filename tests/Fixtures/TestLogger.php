<?php

declare(strict_types=1);

namespace Errata\Tests\Fixtures;

use Override;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * @internal
 */
final class TestLogger extends AbstractLogger
{
    public int $calls = 0;

    public mixed $level = null;

    /** @var array<array-key, mixed> */
    public array $context = [];

    /** @var list<array<array-key, mixed>> */
    public array $contexts = [];

    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        ++$this->calls;
        $this->level = $level;
        $this->context = $context;
        $this->contexts[] = $context;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function contextAt(int $index): array
    {
        return $this->contexts[$index] ?? [];
    }
}
