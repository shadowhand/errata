<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Fingerprint;
use Errata\Problem;
use Errata\Root;
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

        new Fingerprint()->extend($problem, new RuntimeException('boom', 42));

        $expected = hash('xxh64', join([RuntimeException::class, '42', new Root()->relative(__FILE__)], glue: '|'));

        $this->assertSame($expected, $problem->extensions['fingerprint'] ?? null);
    }

    public function testItUsesACustomKeyAndAlgorithm(): void
    {
        $problem = new Problem();

        new Fingerprint(key: 'hash', algo: 'crc32b')->extend($problem, new RuntimeException('boom', 42));

        $expected = hash('crc32b', join([RuntimeException::class, '42', new Root()->relative(__FILE__)], glue: '|'));

        $this->assertSame($expected, $problem->extensions['hash'] ?? null);
    }
}
