<?php

declare(strict_types=1);

namespace Errata\Path;

use Composer\InstalledVersions;

use function array_pop;
use function explode;
use function implode;
use function is_string;
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
final readonly class PathRelativizer
{
    private const string SEPARATOR = '/';

    private string $projectDir;

    public function __construct(?string $projectDir = null)
    {
        // Composer root MUST be resolved like any other directory, it is NOT normalized.
        $projectDir ??= InstalledVersions::getRootPackage()['install_path'];

        $this->projectDir = rtrim(self::resolve($projectDir), self::SEPARATOR);
    }

    public function relativize(string $absolutePath): string
    {
        $path = self::resolve($absolutePath);
        $prefix = $this->projectDir . self::SEPARATOR;

        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        return substr($path, strlen($prefix));
    }

    private static function resolve(string $path): string
    {
        $real = realpath($path);

        if (is_string($real)) {
            return str_replace('\\', self::SEPARATOR, $real);
        }

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

        $resolved = implode(self::SEPARATOR, $segments);

        if ($rooted) {
            return self::SEPARATOR . $resolved;
        }

        return $resolved;
    }
}
