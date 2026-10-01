<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SanitizedObject;

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
