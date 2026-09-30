<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Errata\Trace\Location;
use Override;
use Throwable;

/**
 * @api
 */
final readonly class Trace implements Extension
{
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'trace',
    ) {}

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $problem->extend($this->key, Location::trace($throwable));
    }
}
