<?php

declare(strict_types=1);

namespace Snafu\Path;

use Composer\InstalledVersions;

use function array_pop;
use function explode;
use function implode;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Turns absolute paths into paths relative to the application directory.
 *
 * @internal
 */
final class PathRelativizer
{
    private const string SEPARATOR = '/';

    private readonly string $projectDir;

    /**
     * `$projectDir` defaults to the Composer root package directory.
     */
    public function __construct(?string $projectDir = null)
    {
        $this->projectDir = rtrim(self::resolve($projectDir ?? self::composerRoot()), self::SEPARATOR);
    }

    /**
     * Paths under the project directory lose the prefix and keep `/`
     * separators. Paths outside it stay absolute: a `../../../usr/lib`
     * chain is noise, not information.
     */
    public function relativize(string $absolutePath): string
    {
        $path = self::collapse($absolutePath);
        $prefix = $this->projectDir . self::SEPARATOR;

        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        return substr($path, strlen($prefix));
    }

    /**
     * Composer's `install_path` is unnormalized — it ends in
     * `composer/../../` — so `realpath()` comes first.
     */
    private static function resolve(string $path): string
    {
        $real = realpath($path);

        return self::collapse($real === false ? $path : $real);
    }

    /**
     * Pure string normalization, no filesystem access.
     */
    private static function collapse(string $path): string
    {
        $path = str_replace('\\', self::SEPARATOR, $path);
        $rooted = str_starts_with($path, self::SEPARATOR);
        /** @var list<string> $segments */
        $segments = [];

        foreach (explode(self::SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return ($rooted ? self::SEPARATOR : '') . implode(self::SEPARATOR, $segments);
    }

    /**
     * Composer guarantees `Composer\InstalledVersions` — composer-runtime-api
     * is a hard requirement — so there is no fallback path to test.
     */
    private static function composerRoot(): string
    {
        /** @var array{install_path: string} $package */
        $package = InstalledVersions::getRootPackage();

        return $package['install_path'];
    }
}
