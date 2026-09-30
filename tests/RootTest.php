<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Root;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psl\Exception\InvariantViolationException;

use function Psl\Filesystem\canonicalize;
use function Psl\Filesystem\get_directory;

use const Psl\Filesystem\SEPARATOR;

#[CoversClass(Root::class)]
final class RootTest extends TestCase
{
    /** @var non-empty-string */
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = get_directory(__DIR__);
    }

    public function testItDetectsTheRootPath(): void
    {
        $root = new Root();

        $this->assertSame(canonicalize($this->root), $root->dir);
    }

    public function testItCanonicalizesTheRootDirectory(): void
    {
        $root = new Root(dir: __DIR__ . '/../');

        $this->assertSame(canonicalize($this->root), $root->dir);
    }

    public function testItRequiresTheRootDirectoryToExist(): void
    {
        $this->expectException(InvariantViolationException::class);
        $this->expectExceptionMessageIs('Root directory must exist');

        new Root('/tmp/errata/non-existant-directory');
    }

    public function testItResolvesTheDefaultVendorDirectory(): void
    {
        $root = new Root($this->root);

        $this->assertSame("{$this->root}/vendor", $root->vendor);
    }

    public function testItResolvesAnExistingVendorDirectory(): void
    {
        $root = new Root($this->root, vendor: 'tests');

        $this->assertSame("{$this->root}/tests", $root->vendor);
    }

    public function testItAllowsVendorDirectoryToNotExist(): void
    {
        $root = new Root($this->root, vendor: 'missing');

        $this->assertSame("{$this->root}/missing", $root->vendor);
    }

    public function testItResolvesVendorPathSegments(): void
    {
        $root = new Root($this->root, vendor: 'foo/./bar/../baz/');

        $this->assertSame("{$this->root}/foo/baz", $root->vendor);
    }

    public function testItResolvesAnAbsoluteVendorDirectory(): void
    {
        $root = new Root($this->root, vendor: '/tmp/vendor/');

        $this->assertSame('/tmp/vendor', $root->vendor);
    }

    public function testItDoesNotEscapeTheFilesystemRoot(): void
    {
        $root = new Root($this->root, vendor: '/../vendor');

        $this->assertSame('/vendor', $root->vendor);
    }

    public function testItMakesRelativePaths(): void
    {
        $root = new Root($this->root);

        $this->assertSame('', $root->relative(''));
        $this->assertSame('', $root->relative($this->root));
        $this->assertSame('tests', $root->relative(__DIR__));
        $this->assertSame('tests/RootTest.php', $root->relative(__FILE__));
    }

    public function testItDoesNotModifyPathsOutside(): void
    {
        $root = new Root($this->root);

        $this->assertSame('/tmp', $root->relative('/tmp'));
    }

    public function testItDetectsAppPaths(): void
    {
        $root = new Root($this->root);

        $this->assertTrue($root->isApp($this->root));
        $this->assertTrue($root->isApp($this->root . '/src/Root.php'));
    }

    public function testItRejectsVendorPaths(): void
    {
        $root = new Root($this->root);

        $this->assertFalse($root->isApp($root->vendor));
        $this->assertFalse($root->isApp($this->root . '/vendor/autoload.php'));
    }

    public function testItRejectsPathsOutsideTheRoot(): void
    {
        $root = new Root($this->root);

        $this->assertFalse($root->isApp('/tmp/foo.php'));
    }

    public function testItRejectsEmptyPaths(): void
    {
        $root = new Root($this->root);

        $this->assertFalse($root->isApp(''));
        $this->assertFalse($root->isApp('/'));
        $this->assertFalse($root->isApp(SEPARATOR));
    }
}
