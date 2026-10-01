<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SourceLine;

#[CoversClass(SourceLine::class)]
final class SourceLineTest extends TestCase
{
    public function testItSerializesLineAndCode(): void
    {
        $this->assertSame(
            ['line' => 42, 'code' => '    $value = 1;'],
            new SourceLine(42, '    $value = 1;')->jsonSerialize(),
        );
    }
}
