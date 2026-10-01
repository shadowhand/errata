<?php

declare(strict_types=1);

namespace Snafu\Trace;

use Snafu\Document\SourceBlock;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function file;
use function is_file;
use function is_readable;
use function max;
use function min;

use const FILE_IGNORE_NEW_LINES;

/**
 * Reads the fixed source window around a line of a file.
 *
 * @internal
 */
final class SourceContext
{
    /**
     * Lines kept either side of the reported line: up to 7 lines in total.
     */
    private const int CONTEXT_RADIUS = 3;

    /**
     * Contents of files already read, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private array $files = [];

    /**
     * Returns the reported line ±3 as one block, preserving all whitespace.
     *
     * Unavailable context yields null, distinct from a valid blank block.
     */
    public function window(string $absolutePath, int $line): ?SourceBlock
    {
        $lines = $this->lines($absolutePath);

        if ($lines === [] || $line < 1 || $line > count($lines)) {
            return null;
        }

        $first = max(1, $line - self::CONTEXT_RADIUS);
        $last = min(count($lines), $line + self::CONTEXT_RADIUS);

        return new SourceBlock(
            start: $first,
            end: $last,
            code: array_slice(array: $lines, offset: $first - 1, length: $last - $first + 1),
        );
    }

    /**
     * @return list<string>
     */
    private function lines(string $absolutePath): array
    {
        if (array_key_exists($absolutePath, $this->files)) {
            return $this->files[$absolutePath];
        }

        $lines = [];

        if (is_file($absolutePath) && is_readable($absolutePath)) {
            $read = file($absolutePath, FILE_IGNORE_NEW_LINES);

            if ($read !== false) {
                $lines = array_values($read);
            }
        }

        return $this->files[$absolutePath] = $lines;
    }
}
