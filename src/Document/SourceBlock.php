<?php

declare(strict_types=1);

namespace Snafu\Document;

use JsonSerializable;
use Override;

/**
 * A contiguous block of source with inclusive file line numbers.
 *
 * @api
 */
final readonly class SourceBlock implements JsonSerializable
{
    public function __construct(
        public int $start,
        public int $end,
        /** @var list<string> */
        public array $code,
    ) {}

    /**
     * @return array{start: int, end: int, code: list<string>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['start' => $this->start, 'end' => $this->end, 'code' => $this->code];
    }
}
