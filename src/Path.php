<?php

declare(strict_types=1);

namespace Errata;

use function array_pop;
use function Psl\Filesystem\canonicalize;
use function Psl\Str\Byte\replace;
use function Psl\Str\Byte\split;
use function Psl\Str\Byte\starts_with;
use function Psl\Str\Byte\trim_right;
use function Psl\Str\join;

use const Psl\Filesystem\SEPARATOR;

/**
 * @internal
 */
final readonly class Path
{
    private const string FS = '/';

    /**
     * @param non-empty-string $path
     * @return non-empty-string
     */
    public static function normalize(string $path): string
    {
        // Windows separators are replaced by *nix separators.
        // @mago-expect analysis:invalid-return-statement
        return SEPARATOR !== self::FS ? replace($path, SEPARATOR, self::FS) : $path;
    }

    /**
     * @param non-empty-string $path
     * @return non-empty-string
     */
    public static function canonicalize(string $path, string $root): string
    {
        $real = canonicalize($path);

        if ($real) {
            return self::normalize($real);
        }

        // Paths MUST be normalized and trailing slashes MUST be removed.
        $path = trim_right(self::normalize($path), self::FS);

        // Relative paths MUST be absolute to the app root.
        if (!starts_with($path, self::FS)) {
            $path = "{$root}/{$path}";
        }

        /** @var list<non-empty-string> */
        $segments = [];

        foreach (split($path, self::FS) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return self::FS . join($segments, self::FS);
    }
}
