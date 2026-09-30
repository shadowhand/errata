<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Origin;
use Errata\Problem;
use Errata\Root;
use Errata\Trace\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(Origin::class)]
final class OriginTest extends TestCase
{
    public function testItExtendsTheProblemWithTheFirstAppLocation(): void
    {
        $problem = new Problem();

        new Origin()->extend($problem, $this->catchThrowable());

        /** @var Location|null $origin */
        $origin = $problem->extensions['origin'] ?? null;

        $this->assertInstanceOf(Location::class, $origin);
        $this->assertSame(__FILE__, $origin->file);
    }

    public function testItFallsBackToTheFirstLocationOutsideTheApp(): void
    {
        $problem = new Problem();

        new Origin(new Root('/tmp'))->extend($problem, $this->catchThrowable());

        /** @var Location|null $origin */
        $origin = $problem->extensions['origin'] ?? null;

        $this->assertInstanceOf(Location::class, $origin);
        $this->assertSame(__FILE__, $origin->file);
    }

    public function testItUsesACustomKey(): void
    {
        $problem = new Problem();

        new Origin(key: 'source')->extend($problem, $this->catchThrowable());

        $this->assertArrayHasKey('source', $problem->extensions);
    }

    private function catchThrowable(): Throwable
    {
        try {
            throw new RuntimeException('Fixture exception');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }
}
