<?php

declare(strict_types=1);

namespace Errata\Tests\Document;

use Errata\Document\Frame;
use Errata\Document\Trace;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Trace::class)]
final class TraceTest extends TestCase
{
    public function testItSerializesAsTheFrameList(): void
    {
        $trace = new Trace([new Frame(function: 'run')], true);

        $this->assertSame([['function' => 'run']], self::json($trace));
        $this->assertTrue($trace->truncated);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Trace $trace): array
    {
        return self::decoded(json_decode(
            json: json_encode($trace, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException('The trace did not serialize to a JSON array.');
        }

        return $value;
    }
}
