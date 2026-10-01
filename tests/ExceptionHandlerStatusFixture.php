<?php

declare(strict_types=1);

namespace Snafu\Tests;

use Override;
use RuntimeException;
use Snafu\Http\StatusCodeInterface;

final class ExceptionHandlerStatusFixture extends RuntimeException implements StatusCodeInterface
{
    public function __construct(
        private readonly int $status,
    ) {
        parent::__construct('status fixture');
    }

    #[Override]
    public function getStatusCode(): int
    {
        return $this->status;
    }
}
