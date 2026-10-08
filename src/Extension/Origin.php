<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Errata\Trace\Location;
use Override;
use Throwable;

use function Psl\Filesystem\canonicalize;
use function Psl\Str\Byte\starts_with;

/**
 * @api
 */
final readonly class Origin implements Extension
{
    /** @var non-empty-string|null */
    private ?string $appDir;

    /** @var non-empty-string|null */
    private ?string $vendorDir;

    /**
     * @param string|null $appDir
     * @param string|null $vendorDir
     */
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'origin',
        ?string $appDir = null,
        ?string $vendorDir = null,
    ) {
        $this->appDir = $appDir ? canonicalize($appDir) : null;
        $this->vendorDir = $vendorDir ? canonicalize($vendorDir) : null;
    }

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $origin = $this->findOrigin($throwable);

        if ($this->appDir) {
            $origin = $origin->withoutDirectory($this->appDir);
        }

        $problem->extend($this->key, $origin);
    }

    private function findOrigin(Throwable $throwable): Location
    {
        $locations = Location::trace($throwable);

        foreach ($locations as $location) {
            if ($this->isApp($location)) {
                return $location;
            }
        }

        return $locations[0] ?? new Location('(internal)');
    }

    private function isApp(Location $location): bool
    {
        if ($this->appDir === null) {
            return false;
        }

        if ($this->vendorDir && starts_with($location->file, $this->vendorDir)) {
            return false;
        }

        return starts_with($location->file, $this->appDir);
    }
}
