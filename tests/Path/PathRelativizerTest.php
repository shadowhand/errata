<?php

declare(strict_types=1);

namespace Errata\Tests\Path;

use Errata\Path\PathRelativizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathRelativizer::class)]
final class PathRelativizerTest extends TestCase
{
    public function testItStripsTheProjectDirectoryPrefix(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/src/Foo.php'));
    }

    public function testItStripsThePrefixRegardlessOfTrailingSlash(): void
    {
        $relativizer = new PathRelativizer('/app/');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/src/Foo.php'));
    }

    public function testItDoesNotStripASiblingDirectoryWithASharedPrefix(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('/app2/src/Foo.php', $relativizer->relativize('/app2/src/Foo.php'));
    }

    public function testItLeavesPathsOutsideTheProjectDirectoryAlone(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('/usr/lib/php/Foo.php', $relativizer->relativize('/usr/lib/php/Foo.php'));
    }

    public function testItNormalizesUnnormalizedPaths(): void
    {
        $relativizer = new PathRelativizer('/app/vendor/composer/../..');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/vendor/composer/../../src/Foo.php'));
    }

    public function testItConvertsWindowsSeparators(): void
    {
        $relativizer = new PathRelativizer('C:/app');

        $this->assertSame('src/Foo.php', $relativizer->relativize('C:\app\src\Foo.php'));
    }

    public function testItFallsBackToTheComposerRootPackageDirectory(): void
    {
        $relativizer = new PathRelativizer();

        $this->assertSame(
            'tests/Fixtures/source/window.php',
            $relativizer->relativize(__DIR__ . '/../Fixtures/source/window.php'),
        );
    }
}
