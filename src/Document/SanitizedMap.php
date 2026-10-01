<?php

declare(strict_types=1);

namespace Snafu\Document;

use JsonSerializable;
use Override;

/**
 * A sanitized string-keyed array argument.
 *
 * Exists so string-keyed maps never reach an API boundary as bare PHP
 * arrays, while still serializing as a JSON object.
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
