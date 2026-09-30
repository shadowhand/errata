<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Path;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Psl\Filesystem\get_directory;

#[CoversClass(Path::class)]
final class PathTest extends TestCase
{
    /** @var non-empty-string */
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = get_directory(__DIR__);
    }

    public function testItCanonicalizesPaths(): void
    {
        $this->assertSame(__DIR__, Path::canonicalize(__DIR__, $this->root));
        $this->assertSame(__DIR__, Path::canonicalize(__DIR__ . '/./././/', $this->root));
        $this->assertSame('/', Path::canonicalize('/errata-does-not-exist/./../', $this->root));
        $this->assertSame(get_directory(__DIR__), Path::canonicalize(__DIR__ . '/../', $this->root));
        $this->assertSame(get_directory(__DIR__), Path::canonicalize('foo/bar/../..', $this->root));
    }
}
