<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Errata\Root;
use Override;
use Throwable;

use function hash;
use function Psl\Str\join;

/**
 * @api
 */
final readonly class Fingerprint implements Extension
{
    public function __construct(
        private Root $root = new Root(),
        /** @var non-empty-string */
        private string $key = 'fingerprint',
        /** @var non-empty-string */
        private string $algo = 'xxh64',
    ) {}

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $elements = [
            $throwable::class,
            (string) $throwable->getCode(),
            // File path MUST be made relative for stability across systems.
            $this->root->relative($throwable->getFile()),
        ];

        $problem->extend($this->key, hash($this->algo, join($elements, glue: '|')));
    }
}
