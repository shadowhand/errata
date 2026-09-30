<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Trace;
use Errata\Problem;
use Errata\Trace\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(Trace::class)]
final class TraceTest extends TestCase
{
    public function testItExtendsTheProblemWithTheTrace(): void
    {
        $problem = new Problem();

        new Trace()->extend($problem, $this->catchThrowable());

        /** @var list<Location>|null $trace */
        $trace = $problem->extensions['trace'] ?? null;

        $this->assertIsArray($trace);
        $this->assertNotEmpty($trace);
        $this->assertContainsOnlyInstancesOf(Location::class, $trace);
    }

    public function testItUsesACustomKey(): void
    {
        $problem = new Problem();

        new Trace('locations')->extend($problem, $this->catchThrowable());

        $this->assertArrayHasKey('locations', $problem->extensions);
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
