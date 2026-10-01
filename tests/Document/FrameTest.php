<?php

declare(strict_types=1);

namespace Errata\Tests\Document;

use Errata\Document\Frame;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $frame = new Frame(file: 'src/Foo.php', line: 12, source: '    $x = 1;');

        $this->assertSame('    $x = 1;', $frame->source);
        $this->assertSame('    $x = 1;', $frame->jsonSerialize()['source'] ?? null);

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => '    $x = 1;',
            ],
            self::json($frame),
        );
    }

    public function testItIncludesAnEmptySourceLine(): void
    {
        $frame = new Frame(source: '');

        $this->assertSame(['source' => ''], self::json($frame));
    }

    public function testItIncludesAWhitespaceOnlySourceLine(): void
    {
        $frame = new Frame(source: ' \t ');

        $this->assertSame(['source' => ' \t '], self::json($frame));
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
