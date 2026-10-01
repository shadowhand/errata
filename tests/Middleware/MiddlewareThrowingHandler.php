<?php

declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Errata\Document\Problem;
use Errata\ExceptionHandlerInterface;
use Override;
use RuntimeException;
use Throwable;

final class MiddlewareThrowingHandler implements ExceptionHandlerInterface
{
    #[Override]
    public function handle(Throwable $exception): Problem
    {
        throw new RuntimeException('handler is broken');
    }
}
