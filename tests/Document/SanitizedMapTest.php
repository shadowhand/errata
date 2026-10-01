<?php

declare(strict_types=1);

namespace Errata\Tests\Document;

use Errata\Document\SanitizedMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SanitizedMap::class)]
final class SanitizedMapTest extends TestCase
{
    public function testItSerializesItsEntries(): void
    {
        $this->assertSame(
            ['alpha' => 1, 'beta' => 'two'],
            new SanitizedMap(['alpha' => 1, 'beta' => 'two'])->jsonSerialize(),
        );
    }
}
