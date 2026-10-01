<?php

declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Override;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class MiddlewareThrowingLogger extends AbstractLogger
{
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('logger is broken');
    }
}
