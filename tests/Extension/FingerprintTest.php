<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Fingerprint;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function hash;
use function Psl\Str\join;

#[CoversClass(Fingerprint::class)]
final class FingerprintTest extends TestCase
{
    public function testItExtendsTheProblemWithAFingerprint(): void
    {
        $problem = new Problem();
        $exception = new RuntimeException('boom', 42);

        $parts = [
            $exception::class,
            (string) $exception->getCode(),
            $exception->getFile(),
        ];

        new Fingerprint()->extend($problem, $exception);

        $expected = hash('xxh64', join($parts, glue: '|'));

        $this->assertSame($expected, $problem->extensions['fingerprint'] ?? null);
    }

    public function testItMakesTheFilePathRelativeWithAppDir(): void
    {
        $problem = new Problem();
        $exception = new RuntimeException('boom', 42);

        $parts = [
            $exception::class,
            (string) $exception->getCode(),
            'tests/Extension/FingerprintTest.php', // __FILE__ made relative by appDir
        ];

        new Fingerprint(appDir: __DIR__ . '/../..')->extend($problem, $exception);

        $expected = hash('xxh64', join($parts, glue: '|'));

        $this->assertSame($expected, $problem->extensions['fingerprint'] ?? null);
    }

    public function testItUsesACustomKeyAndAlgorithm(): void
    {
        $problem = new Problem();
        $exception = new RuntimeException('boom', 42);

        $parts = [
            $exception::class,
            (string) $exception->getCode(),
            $exception->getFile(),
        ];

        new Fingerprint(key: 'hash', algo: 'crc32b')->extend($problem, $exception);

        $expected = hash('crc32b', join($parts, glue: '|'));

        $this->assertSame($expected, $problem->extensions['hash'] ?? null);
    }
}
