<?php

declare(strict_types=1);

namespace Errata\Tests\Extension;

use ArrayIterator;
use Errata\Extension\Code;
use Errata\Extension\ExtensionList;
use Errata\Extension\Message;
use Errata\Problem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Psl\Vec\values;

#[CoversClass(ExtensionList::class)]
final class ExtensionListTest extends TestCase
{
    public function testItAppliesNoExtensionsWhenEmpty(): void
    {
        $problem = new Problem();

        new ExtensionList()->extend($problem, new RuntimeException('boom'));

        $this->assertSame([], $problem->extensions);
    }

    public function testItAppliesASingleExtension(): void
    {
        $problem = new Problem();

        new ExtensionList(new Message())->extend($problem, new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
    }

    public function testItAppliesEveryExtension(): void
    {
        $problem = new Problem();

        new ExtensionList(new Message(), new Code('code'))->extend($problem, new RuntimeException('boom', 42));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
        $this->assertSame('42', $problem->extensions['code'] ?? null);
    }

    public function testItCanNestExtensionLists(): void
    {
        $problem = new Problem();

        new ExtensionList(new ExtensionList(new Message()))->extend($problem, new RuntimeException('boom'));

        $this->assertSame('boom', $problem->extensions['message'] ?? null);
    }

    public function testItIteratesOverTheExtensions(): void
    {
        $message = new Message();
        $code = new Code();
        $list = new ExtensionList($message, $code);

        $this->assertInstanceOf(ArrayIterator::class, $list->getIterator());
        $this->assertSame([$message, $code], values($list));
    }

    public function testItIteratesOverNoExtensionsWhenEmpty(): void
    {
        $this->assertSame([], values(new ExtensionList()));
    }
}
