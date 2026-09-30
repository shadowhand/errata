<?php

declare(strict_types=1);

namespace Errata\Extension;

use Errata\Problem;
use Errata\Root;
use Errata\Trace\Location;
use Override;
use Throwable;

/**
 * @api
 */
final readonly class Origin implements Extension
{
    public function __construct(
        private Root $root = new Root(),
        /** @var non-empty-string */
        private string $key = 'origin',
    ) {}

    #[Override]
    public function extend(Problem $problem, Throwable $throwable): void
    {
        $problem->extend($this->key, $this->findOrigin($throwable));
    }

    private function findOrigin(Throwable $throwable): Location
    {
        $locations = Location::trace($throwable);

        foreach ($locations as $location) {
            if ($this->root->isApp($location->file)) {
                return $location;
            }
        }

        return $locations[0] ?? new Location('(internal)');
    }
}
