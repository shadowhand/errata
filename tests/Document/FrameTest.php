<?php

declare(strict_types=1);

namespace Snafu\Tests\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\Frame;
use Snafu\Document\SourceLine;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Frame::class)]
final class FrameTest extends TestCase
{
    public function testItIsEmptyWhenNothingIsKnown(): void
    {
        $this->assertSame([], new Frame()->jsonSerialize());
    }

    public function testItOmitsNullMembersAndEmptySource(): void
    {
        $frame = new Frame(
            file: 'src/Foo.php',
            line: 12,
            function: 'run',
            class: 'App\Foo',
            type: '->',
            args: [1],
            source: [],
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
        $frame = new Frame(file: 'src/Foo.php', line: 12, source: [new SourceLine(12, '    $x = 1;')]);

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => [['line' => 12, 'code' => '    $x = 1;']],
            ],
            self::json($frame),
        );
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
