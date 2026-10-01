<?php

declare(strict_types=1);

namespace Errata\Trace;

use function array_key_exists;
use function array_values;
use function file;
use function is_file;
use function is_readable;
use function trim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Reads one line of source from a file.
 *
 * @internal
 */
final class SourceContext
{
    /**
     * Contents of files already read, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private array $files = [];

    /**
     * Returns the source line at `$line`, trimmed of surrounding
     * whitespace.
     *
     * Unavailable source yields null, distinct from a valid blank line.
     */
    public function line(string $absolutePath, int $line): ?string
    {
        $source = $this->lines($absolutePath)[$line - 1] ?? null;

        return $source === null ? null : trim($source);
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
