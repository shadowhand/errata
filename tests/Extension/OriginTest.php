<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Origin;
use Errata\Problem;
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
    }

    public function testItFallsBackToTheFirstLocationOutsideTheApp(): void
    {
        $problem = new Problem();

        new Origin(appDir: '/tmp')->extend($problem, $this->catchThrowable());

        /** @var Location|null $origin */
        $origin = $problem->extensions['origin'] ?? null;

        $this->assertInstanceOf(Location::class, $origin);
    }

    public function testItIgnoresVendorLocations(): void
    {
        $problem = new Problem();

        new Origin(appDir: __DIR__ . '/../..', vendorDir: __DIR__ . '/../../vendor')->extend(
            $problem,
            $this->catchThrowable(),
        );

        /** @var Location|null $origin */
        $origin = $problem->extensions['origin'] ?? null;

        $this->assertInstanceOf(Location::class, $origin);
        $this->assertSame('tests/Extension/OriginTest.php', $origin->file);
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
            $this->throwException(new RuntimeException('Fixture exception'));
            $this->fail('Should have thrown exception');
        } catch (Throwable $throwable) {
            return $throwable;
        }
    }
}
