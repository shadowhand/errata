<?php

declare(strict_types=1);

namespace Snafu\Tests\Middleware;

use Override;
use RuntimeException;
use Snafu\Document\Problem;
use Snafu\ExceptionHandlerInterface;
use Throwable;

final class MiddlewareThrowingHandler implements ExceptionHandlerInterface
{
    #[Override]
    public function handle(Throwable $exception): Problem
    {
        throw new RuntimeException('handler is broken');
    }
}
