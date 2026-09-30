<?php

declare(strict_types=1);

namespace Errata\Extension;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Errata\Problem;
use Override;
use Throwable;

/**
 * @api
 */
final readonly class Timestamp implements Extension
{
    private DateTimeZone $tz;

    public function __construct(
        /** @var non-empty-string */
        private string $key = 'timestamp',
        /** @var non-empty-string */
        private string $format = DateTimeInterface::RFC3339_EXTENDED,
        /** @var non-empty-string */
        private string $timezone = 'UTC',
    ) {
        $this->tz = new DateTimeZone($this->timezone);
    }

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $timestamp = new DateTimeImmutable(timezone: $this->tz);

        $problem->extend($this->key, $timestamp->format($this->format));
    }
}
