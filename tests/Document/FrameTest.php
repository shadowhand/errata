<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\Frame;
use Snafu\Document\SourceBlock;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Frame::class)]
final class FrameTest extends TestCase
{
    public function testItIsEmptyWhenNothingIsKnown(): void
    {
        $frame = new Frame();

        $this->assertNull($frame->source);
        $this->assertSame([], $frame->jsonSerialize());
    }

    public function testItOmitsNullMembersAndNullSource(): void
    {
        $frame = new Frame(
            file: 'src/Foo.php',
            line: 12,
            function: 'run',
            class: 'App\Foo',
            type: '->',
            args: [1],
            source: null,
        );

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'function' => 'run',
                'class' => 'App\Foo',
                'type' => '->',
                'args' => [1],
            ],
            $frame->jsonSerialize(),
        );
    }

    public function testItIncludesSourceWhenPresent(): void
    {
        $source = new SourceBlock(start: 11, end: 13, code: ['{', '    $x = 1;', '}']);
        $frame = new Frame(file: 'src/Foo.php', line: 12, source: $source);

        $this->assertSame($source, $frame->source);
        $this->assertSame($source, $frame->jsonSerialize()['source'] ?? null);

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => ['start' => 11, 'end' => 13, 'code' => ['{', '    $x = 1;', '}']],
            ],
            self::json($frame),
        );
    }

    public function testItIncludesSourceWithEmptyCode(): void
    {
        $frame = new Frame(source: new SourceBlock(start: 12, end: 12, code: ['']));

        $this->assertSame(['source' => ['start' => 12, 'end' => 12, 'code' => ['']]], self::json($frame));
    }

    public function testItIncludesSourceWithWhitespaceOnlyCode(): void
    {
        $frame = new Frame(source: new SourceBlock(start: 11, end: 12, code: [' \t ', '\t  ']));

        $this->assertSame(['source' => ['start' => 11, 'end' => 12, 'code' => [' \t ', '\t  ']]], self::json($frame));
    }

    public function testItEmitsAnEmptyArgumentListThatPhpReported(): void
    {
        $this->assertSame(['args' => []], new Frame(args: [])->jsonSerialize());
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Frame $frame): array
    {
        return self::decoded(json_decode(
            json: json_encode($frame, JSON_THROW_ON_ERROR),
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
            throw new LogicException('The frame did not serialize to a JSON object.');
        }

        return $value;
    }
}
