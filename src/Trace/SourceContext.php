<?php

declare(strict_types=1);

namespace Snafu\Trace;

use Snafu\Document\SourceLine;

use function array_key_exists;
use function array_values;
use function count;
use function file;
use function is_file;
use function is_readable;
use function max;
use function min;
use function rtrim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Reads the fixed source window around a line of a file.
 *
 * @internal
 */
final class SourceContext
{
    /**
     * Lines kept either side of the reported line: 5 lines in total.
     */
    private const int CONTEXT_RADIUS = 2;

    /**
     * Contents of files already read, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private array $files = [];

    /**
     * Returns the 5 lines around `$line`, with blank lines removed.
     *
     * A file that cannot be read, or a line outside the file, yields an
     * empty list: "no context" and "nothing survived blank stripping"
     * are the same thing to a consumer.
     *
     * @return list<SourceLine>
     */
    public function window(string $absolutePath, int $line): array
    {
        $lines = $this->lines($absolutePath);

        if ($lines === [] || $line < 1 || $line > count($lines)) {
            return [];
        }

        $first = max(1, $line - self::CONTEXT_RADIUS);
        $last = min(count($lines), $line + self::CONTEXT_RADIUS);

        $window = [];

        for ($current = $first; $current <= $last; $current++) {
            $code = rtrim($lines[$current - 1] ?? '');

            if ($code === '') {
                continue;
            }

            $window[] = new SourceLine($current, $code);
        }

        return $window;
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
