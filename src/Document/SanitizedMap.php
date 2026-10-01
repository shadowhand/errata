<?php

declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * A sanitized string-keyed array argument.
 *
 * @api
 */
final readonly class SanitizedMap implements JsonSerializable
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $entries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->entries;
    }
}
