<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use Errata\Extension\Message;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Message::class)]
final class MessageTest extends TestCase
{
    public function testItExtendsTheProblemWithTheMessage(): void
    {
        $problem = new Problem();

        new Message()->extend($problem, new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
    }

    public function testItUsesACustomKey(): void
    {
        $problem = new Problem();

        new Message('custom')->extend($problem, new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['custom'] ?? null);
    }
}
