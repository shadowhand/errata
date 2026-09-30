<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Override;
use ReflectionObject;
use Throwable;

/**
 * @api
 */
final readonly class Type implements Extension
{
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'exception',
        private bool $short = true,
    ) {}

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $problem->extend($this->key, $this->throwableType($throwable));
    }

    private function throwableType(Throwable $throwable): string
    {
        if ($this->short) {
            return new ReflectionObject($throwable)->getShortName();
        }

        return $throwable::class;
    }
}
