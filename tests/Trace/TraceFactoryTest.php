<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SourceBlock;
use Snafu\Document\Trace;
use Snafu\Path\PathRelativizer;
use Snafu\Trace\ArgumentSanitizer;
use Snafu\Trace\SourceContext;
use Snafu\Trace\TraceFactory;
use stdClass;

use function array_column;
use function array_slice;
use function bin2hex;
use function dirname;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

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
        $this->assertInstanceOf(SourceBlock::class, $trace->frames[0]->source ?? null);
        $this->assertNull($trace->frames[1]->source ?? null);
        $this->assertSame(
            [
                [
                    'file' => 'tests/Fixtures/source/window.php',
                    'line' => 5,
                    'function' => 'inner',
                    'source' => [
                        'start' => 2,
                        'end' => 8,
                        'code' => [
                            '',
                            'function snafu_fixture_alpha(): void',
                            '{',
                            '    $alpha = 1;',
                            '',
                            '    $beta = 2;',
                            '}',
                        ],
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

    public function testItRetainsEmptyAndWhitespaceOnlySource(): void
    {
        foreach ([
            ["\n", 1, ['']],
            [" \t \r\n\t  \r\n", 2, [" \t ", "\t  "]],
        ] as [$contents, $end, $code]) {
            $path = sys_get_temp_dir() . '/snafu-trace-source-' . bin2hex(random_bytes(8)) . '.php';
            file_put_contents(filename: $path, data: $contents);

            try {
                $trace = $this->factory->frames([['file' => $path, 'line' => 1]]);

                $this->assertInstanceOf(SourceBlock::class, $trace->frames[0]->source ?? null);
                $this->assertSame(
                    [['file' => $path, 'line' => 1, 'source' => ['start' => 1, 'end' => $end, 'code' => $code]]],
                    $this->serialize($trace),
                );
            } finally {
                unlink($path);
            }
        }
    }

    public function testItOmitsSourceWhenTheFrameHasNoLine(): void
    {
        $trace = $this->factory->frames([['file' => $this->root . self::ROOT_FIXTURE, 'function' => 'inner']]);

        $this->assertNull($trace->frames[0]->source ?? null);
        $this->assertSame(
            [['file' => 'tests/Fixtures/source/window.php', 'function' => 'inner']],
            $this->serialize($trace),
        );
    }

    public function testItRelativizesPathsEmbeddedInClosureNames(): void
    {
        $trace = $this->factory->frames([
            [
                'function' => '{closure:' . $this->root . '/demo/full.php:12}',
                'line' => 12,
            ],
            [
                'function' => '{closure:' . $this->root . "/demo/full\nview.php:13}",
                'line' => 13,
            ],
        ]);

        $this->assertSame(
            [
                ['line' => 12, 'function' => '{closure:demo/full.php:12}'],
                ['line' => 13, 'function' => "{closure:demo/full\nview.php:13}"],
            ],
            $this->serialize($trace),
        );
    }

    public function testItRelativizesPathsEmbeddedInAnonymousClassNames(): void
    {
        $trace = $this->factory->frames([
            [
                'line' => 36,
                'class' =>
                    'Psr\\Http\\Server\\RequestHandlerInterface@anonymous'
                        . "\0"
                        . $this->root
                        . '/demo/bootstrap.php:36$0',
                'type' => '->',
            ],
            [
                'line' => 37,
                'class' =>
                    'Psr\\Http\\Server\\RequestHandlerInterface@anonymous'
                        . "\0"
                        . $this->root
                        . "/demo/bootstrap\nhandler.php:37$0",
                'type' => '->',
            ],
        ]);

        $this->assertSame(
            [
                [
                    'line' => 36,
                    'class' => 'Psr\\Http\\Server\\RequestHandlerInterface@anonymous' . "\0demo/bootstrap.php:36$0",
                    'type' => '->',
                ],
                [
                    'line' => 37,
                    'class' =>
                        'Psr\\Http\\Server\\RequestHandlerInterface@anonymous' . "\0demo/bootstrap\nhandler.php:37$0",
                    'type' => '->',
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

        $this->assertNull($trace->frames[0]->source ?? null);
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

        $this->assertNull($trace->frames[0]->source ?? null);
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
