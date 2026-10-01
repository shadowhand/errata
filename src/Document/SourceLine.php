<?php

declare(strict_types=1);

namespace Snafu\Document;

use JsonSerializable;
use Override;

/**
 * One line of source context.
 *
 * @api
 */
final readonly class SourceLine implements JsonSerializable
{
    public function __construct(
        public int $line,
        public string $code,
    ) {}

    /**
     * @return array{line: int, code: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['line' => $this->line, 'code' => $this->code];
    }
}
