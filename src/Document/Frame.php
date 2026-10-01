<?php

declare(strict_types=1);

namespace Snafu\Document;

use JsonSerializable;
use Override;

/**
 * One frame of a trace.
 *
 * @api
 */
final readonly class Frame implements JsonSerializable
{
    // @mago-ignore lint:excessive-parameter-list
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $function = null,
        public ?string $class = null,
        public ?string $type = null,
        /** @var list<mixed>|null */
        public ?array $args = null,
        /** @var list<SourceLine> */
        public array $source = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $frame = [];

        if ($this->file !== null) {
            $frame['file'] = $this->file;
        }

        if ($this->line !== null) {
            $frame['line'] = $this->line;
        }

        if ($this->function !== null) {
            $frame['function'] = $this->function;
        }

        if ($this->class !== null) {
            $frame['class'] = $this->class;
        }

        if ($this->type !== null) {
            $frame['type'] = $this->type;
        }

        if ($this->args !== null) {
            $frame['args'] = $this->args;
        }

        if ($this->source !== []) {
            $frame['source'] = $this->source;
        }

        return $frame;
    }
}
