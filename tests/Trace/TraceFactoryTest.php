<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\Trace;
use Snafu\Path\PathRelativizer;
use Snafu\Trace\ArgumentSanitizer;
use Snafu\Trace\SourceContext;
use Snafu\Trace\TraceFactory;
use stdClass;

use function array_column;
use function array_slice;
use function dirname;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(TraceFactory::class)]
final class TraceFactoryTest extends TestCase
{
    private const string ROOT_FIXTURE = '/tests/Fixtures/source/window.php';

    private string $root;

    private TraceFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->root = dirname(path: __DIR__, levels: 2);
        $this->factory = new TraceFactory(
            new PathRelativizer($this->root),
            new SourceContext(),
            new ArgumentSanitizer(),
            traceLimit: 30,
        );
    }

    public function testItKeepsTheInnermostFrameFirst(): void
    {
        $trace = $this->factory->frames([
            ['file' => $this->root . self::ROOT_FIXTURE, 'line' => 5, 'function' => 'inner'],
            ['file' => $this->root . '/src/Outer.php', 'line' => 3, 'function' => 'outer'],
        ]);

        $this->assertFalse($trace->truncated);
        $this->assertSame(
            [
                [
                    'file' => 'tests/Fixtures/source/window.php',
                    'line' => 5,
                    'function' => 'inner',
                    'source' => [
                        ['line' => 3, 'code' => 'function snafu_fixture_alpha(): void'],
                        ['line' => 4, 'code' => '{'],
                        ['line' => 5, 'code' => '    $alpha = 1;'],
                        ['line' => 7, 'code' => '    $beta = 2;'],
                    ],
                ],
                [
                    'file' => 'src/Outer.php',
                    'line' => 3,
                    'function' => 'outer',
                ],
            ],
            $this->serialize($trace),
        );
    }

    public function testItCarriesClassTypeAndArguments(): void
    {
        $trace = $this->factory->frames([
            [
                'file' => null,
                'line' => null,
                'function' => 'run',
                'class' => 'App\Thing',
                'type' => '->',
                'args' => ['plain', new stdClass()],
            ],
        ]);

        $this->assertSame(
            [
                [
                    'function' => 'run',
                    'class' => 'App\Thing',
                    'type' => '->',
                    'args' => ['plain', ['@class' => 'stdClass', 'props' => []]],
                ],
            ],
            $this->serialize($trace),
        );
    }

    public function testItKeepsFramesWithoutAFile(): void
    {
        $trace = $this->factory->frames([['function' => 'strlen', 'args' => []]]);

        $this->assertSame([['function' => 'strlen', 'args' => []]], $this->serialize($trace));
    }

    public function testItOmitsArgsWhenTheTraceHasNoArgsKey(): void
    {
        $trace = $this->factory->frames([['function' => 'strlen']]);

        $this->assertSame([['function' => 'strlen']], $this->serialize($trace));
    }

    public function testItIgnoresEntriesOfTheWrongType(): void
    {
        $trace = $this->factory->frames([[
            'file' => 42,
            'line' => 'twelve',
            'function' => ['nope'],
            'args' => 'not-an-array',
        ]]);

        $this->assertSame([[]], $this->serialize($trace));
    }

    public function testItKeepsTheInnermostFramesWhenTheLimitIsReached(): void
    {
        $trace = $this->factory->frames($this->manyFrames(40));
        /** @var list<array<string, mixed>> $frames */
        $frames = $this->serialize($trace);
        $functions = array_column($frames, 'function');

        $this->assertTrue($trace->truncated);
        $this->assertCount(30, $trace->frames);
        $this->assertSame(['frame-0'], array_slice(array: $functions, offset: 0, length: 1));
        $this->assertSame(['frame-29'], array_slice($functions, -1));
    }

    public function testItDoesNotFlagATraceAtExactlyTheLimit(): void
    {
        $trace = $this->factory->frames($this->manyFrames(30));

        $this->assertFalse($trace->truncated);
        $this->assertCount(30, $trace->frames);
    }

    public function testItSkipsTheSourceWindowWhenTheLineIsOutOfRange(): void
    {
        $trace = $this->factory->frames([[
            'file' => $this->root . self::ROOT_FIXTURE,
            'line' => 900,
            'function' => 'inner',
        ]]);

        $this->assertSame(
            [['file' => 'tests/Fixtures/source/window.php', 'line' => 900, 'function' => 'inner']],
            $this->serialize($trace),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function serialize(Trace $trace): array
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

    /**
     * @return list<array<string, mixed>>
     */
    private function manyFrames(int $count): array
    {
        $trace = [];

        for ($i = 0; $i < $count; $i++) {
            $trace[] = ['function' => 'frame-' . $i];
        }

        return $trace;
    }
}
