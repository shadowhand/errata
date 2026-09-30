<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Code;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Code::class)]
final class CodeTest extends TestCase
{
    public function testItExtendsTheProblemWithTheCode(): void
    {
        $problem = new Problem();

        new Code()->extend($problem, new RuntimeException('boom', 42));

        $this->assertSame('42', $problem->extensions['code'] ?? null);
    }

    public function testItUsesACustomKey(): void
    {
        $problem = new Problem();

        new Code('num')->extend($problem, new RuntimeException('boom', 42));

        $this->assertSame('42', $problem->extensions['num'] ?? null);
    }
}
