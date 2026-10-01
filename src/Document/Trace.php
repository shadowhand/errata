<?php

declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * The trace section of a problem document.
 *
 * @api
 */
final readonly class Trace implements JsonSerializable
{
    public function __construct(
        /** @var list<Frame> */
        public array $frames,
        public bool $truncated,
    ) {}

    /**
     * @return list<Frame>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->frames;
    }
}
