<?php

declare(strict_types=1);

namespace Errata;

use Composer\InstalledVersions;

use function Psl\Filesystem\canonicalize;
use function Psl\Filesystem\is_directory;
use function Psl\invariant;
use function Psl\Str\Byte\after;
use function Psl\Str\Byte\starts_with;

use const Psl\Filesystem\SEPARATOR;

/**
 * @api
 */
final readonly class Root
{
    /** @var non-empty-string */
    public string $dir;

    /** @var non-empty-string */
    public string $vendor;

    /**
     * @param non-empty-string $vendor
     */
    public function __construct(string $dir = '', string $vendor = 'vendor')
    {
        if ($dir === '' || $dir === '.') {
            $dir = InstalledVersions::getRootPackage()['install_path'];
        }

        $dir = canonicalize($dir);

        invariant($dir && is_directory($dir), message: 'Root directory must exist');

        $this->dir = Path::normalize($dir);
        $this->vendor = Path::canonicalize($vendor, $this->dir);
    }

    public function isApp(string $path): bool
    {
        if ($path === '' || $path === '/' || $path === SEPARATOR) {
            return false;
        }

        $path = Path::normalize($path);

        if (starts_with($path, $this->vendor)) {
            return false;
        }

        return starts_with($path, $this->dir);
    }

    public function relative(string $path): string
    {
        if ($path === '' || $path === $this->dir) {
            return '';
        }

        $path = Path::normalize($path);
        $prefix = "{$this->dir}/";

        if (starts_with($path, $prefix)) {
            return after($path, $prefix) ?? '';
        }

        return $path;
    }
}
