<?php

declare(strict_types=1);

namespace Errata\Tests\Document;

use Errata\Document\SanitizedObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SanitizedObject::class)]
final class SanitizedObjectTest extends TestCase
{
    public function testItSerializesClassAndProperties(): void
    {
        $this->assertSame(
            ['@class' => 'App\Thing', 'props' => ['id' => 1]],
            new SanitizedObject('App\Thing', ['id' => 1])->jsonSerialize(),
        );
    }
}
