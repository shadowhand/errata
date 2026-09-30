<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Type;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Type::class)]
final class TypeTest extends TestCase
{
    public function testItExtendsTheProblemWithTheShortTypeName(): void
    {
        $problem = new Problem();

        new Type()->extend($problem, new RuntimeException());

        $this->assertSame('RuntimeException', $problem->extensions['exception'] ?? null);
    }

    public function testItExtendsTheProblemWithTheFullyQualifiedTypeName(): void
    {
        $problem = new Problem();

        new Type(short: false)->extend($problem, new RuntimeException());

        $this->assertSame(RuntimeException::class, $problem->extensions['exception'] ?? null);
    }

    public function testItUsesACustomKey(): void
    {
        $problem = new Problem();

        new Type('custom')->extend($problem, new RuntimeException());

        $this->assertSame('RuntimeException', $problem->extensions['custom'] ?? null);
    }
}
