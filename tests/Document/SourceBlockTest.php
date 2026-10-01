<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SourceBlock;

#[CoversClass(SourceBlock::class)]
final class SourceBlockTest extends TestCase
{
    public function testItSerializesInclusiveBoundsAndCode(): void
    {
        $this->assertSame(
            ['start' => 39, 'end' => 41, 'code' => ['first', '', 'last']],
            new SourceBlock(start: 39, end: 41, code: ['first', '', 'last'])->jsonSerialize(),
        );
    }
}
