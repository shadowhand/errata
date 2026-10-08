<?php

declare(strict_types=1);

namespace Errata\Tests\Trace;

use Closure;
use Errata\Trace\Location;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;
use function Psl\Iter\count;

#[CoversClass(Location::class)]
final class LocationTest extends TestCase
{
    public function testItFormatsAFileAndLine(): void
    {
        $location = new Location('/app/src/File.php', 42);

        $this->assertSame('/app/src/File.php:42', $location->toString());
        $this->assertSame($location->toString(), $location->jsonSerialize());
        $this->assertSame($location->toString(), (string) $location);
    }

    public function testItFormatsAFileWithoutALine(): void
    {
        foreach ([0, -1] as $line) {
            $location = new Location('/app/src/File.php', $line);

            $this->assertSame('/app/src/File.php', $location->toString());
        }
    }

    public function testItDefaultsTheLineToZero(): void
    {
        $location = new Location('/app/src/File.php');

        $this->assertSame('/app/src/File.php', $location->toString());
    }

    public function testItRemovesTheDirectoryPrefix(): void
    {
        $valid = new Location('/app/src/File.php')->withoutDirectory('/app');
        $invalid = new Location('/app/src/File.php')->withoutDirectory('/tmp');

        $this->assertSame('src/File.php', $valid->toString());
        $this->assertSame('/app/src/File.php', $invalid->toString());
    }

    public function testItTracesTheThrowableAndItsFrames(): void
    {
        $throwable = $this->catchThrowable($this->throwFromHelper(...));

        $locations = Location::trace($throwable);

        $this->assertNotEmpty($locations);

        $first = $locations[0] ?? null;
        $this->assertNotNull($first);

        // The throwable's own location MUST come first.
        $this->assertSame($throwable->getFile(), $first->file);
        $this->assertSame($throwable->getLine(), $first->line);

        // Every traced location MUST have a file and a positive line.
        foreach ($locations as $location) {
            $this->assertNotSame('', $location->file);
            $this->assertGreaterThan(0, $location->line);
        }
    }

    public function testItSkipsFramesWithoutAFileOrLine(): void
    {
        $throwable = $this->catchThrowable($this->throwFromArrayMap(...));

        $locations = Location::trace($throwable);

        // The closure invoked internally by array_map has no file or line and MUST be skipped, so the
        // number of locations MUST be less than the number of raw frames plus the throwable itself.
        $this->assertLessThan(count($throwable->getTrace()) + 1, count($locations));

        foreach ($locations as $location) {
            $this->assertNotSame('', $location->file);
            $this->assertGreaterThan(0, $location->line);
        }
    }

    private function throwFromHelper(): never
    {
        throw new RuntimeException('Fixture exception');
    }

    private function throwFromArrayMap(): void
    {
        // @mago-expect lint:psl-array-functions
        array_map(static fn(): never => throw new RuntimeException('Fixture exception'), [1]);
    }

    private function catchThrowable(Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        $this->fail('Expected the callback to throw');
    }
}
