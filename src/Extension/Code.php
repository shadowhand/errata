<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Override;
use Throwable;

/**
 * @api
 */
final readonly class Code implements Extension
{
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'code',
    ) {}

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $problem->extend($this->key, (string) $throwable->getCode());
    }
}
