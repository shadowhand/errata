<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SanitizedMap;

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
