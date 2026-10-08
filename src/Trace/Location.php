<?php

declare(strict_types=1);

namespace Errata\Trace;

use JsonSerializable;
use Override;
use Stringable;
use Throwable;

use function Psl\Str\Byte\after;
use function Psl\Str\Byte\starts_with;

use const Psl\Filesystem\SEPARATOR;

/**
 * @internal
 */
final readonly class Location implements JsonSerializable, Stringable
{
    /**
     * @return list<self>
     */
    public static function trace(Throwable $throwable): array
    {
        $data = [];

        $file = $throwable->getFile();
        $line = $throwable->getLine();

        if ($file && $line > 0) {
            $data[] = new self($file, $line);
        }

        /** @var array{file?: string, line?: int} */
        foreach ($throwable->getTrace() as $trace) {
            $file = $trace['file'] ?? null;
            $line = $trace['line'] ?? 0;

            if ($file && $line > 0) {
                $data[] = new self($file, $line);
            }
        }

        return $data;
    }

    public function __construct(
        /** @var non-empty-string */
        public string $file,
        public int $line = 0,
    ) {}

    /**
     * @param non-empty-string $dir
     */
    public function withoutDirectory(string $dir): self
    {
        if (!starts_with($this->file, $dir)) {
            return $this;
        }

        // @mago-expect analysis:possibly-invalid-argument
        // @mago-expect analysis:possibly-null-argument
        return new self(after($this->file, $dir . SEPARATOR), $this->line);
    }

    public function toString(): string
    {
        if ($this->line < 1) {
            return $this->file;
        }

        return "{$this->file}:{$this->line}";
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    #[Override]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
