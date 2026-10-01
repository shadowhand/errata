<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SourceBlock;
use Snafu\Trace\SourceContext;

use function bin2hex;
use function file_put_contents;
use function is_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(SourceContext::class)]
final class SourceContextTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/source/window.php';

    private SourceContext $context;

    #[Override]
    protected function setUp(): void
    {
        $this->context = new SourceContext();
    }

    public function testItReturnsSevenContiguousLinesAndPreservesBlankLines(): void
    {
        $source = $this->context->window(self::FIXTURE, 5);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(
            [
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
            $source->jsonSerialize(),
        );
    }

    public function testItClampsTheWindowAtTheStartWithoutCompensating(): void
    {
        $source = $this->context->window(self::FIXTURE, 1);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(
            [
                'start' => 1,
                'end' => 4,
                'code' => ['<?php declare(strict_types=1);', '', 'function snafu_fixture_alpha(): void', '{'],
            ],
            $source->jsonSerialize(),
        );
    }

    public function testItClampsTheWindowAtTheEndWithoutCompensating(): void
    {
        $source = $this->context->window(self::FIXTURE, 13);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(
            [
                'start' => 10,
                'end' => 13,
                'code' => ['function snafu_fixture_omega(): void', '{', '    $omega = 3;', '}'],
            ],
            $source->jsonSerialize(),
        );
    }

    public function testItReturnsNullForAReportedLineOutsideTheFile(): void
    {
        $this->assertNull($this->context->window(self::FIXTURE, 0));
        $this->assertNull($this->context->window(self::FIXTURE, 999));
    }

    public function testItReturnsNullForAnEmptyFile(): void
    {
        $this->assertNull($this->windowFromContents('', 1));
    }

    public function testItReturnsNullForAMissingFile(): void
    {
        $this->assertNull($this->context->window('/nonexistent/snafu/window.php', 1));
    }

    public function testItReturnsNullForADirectory(): void
    {
        $this->assertNull($this->context->window(__DIR__ . '/../Fixtures/source', 1));
    }

    public function testItPreservesWhitespaceAndNormalizesLineSeparators(): void
    {
        $source = $this->windowFromContents("\t  \r\n\r\n  \$value = 1; \t\r\n \t", 3);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(
            ['start' => 1, 'end' => 4, 'code' => ["\t  ", '', "  \$value = 1; \t", " \t"]],
            $source->jsonSerialize(),
        );
    }

    public function testItKeepsAnAllBlankWindowAsPresentSource(): void
    {
        $source = $this->windowFromContents("\n\n\n\n\n\n\n", 4);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(['start' => 1, 'end' => 7, 'code' => ['', '', '', '', '', '', '']], $source->jsonSerialize());
    }

    public function testItKeepsAnEmptySingleLineBlockDistinctFromMissingContext(): void
    {
        $source = $this->windowFromContents("\n", 1);

        $this->assertInstanceOf(SourceBlock::class, $source);
        $this->assertSame(['start' => 1, 'end' => 1, 'code' => ['']], $source->jsonSerialize());
    }

    public function testItCachesFileContentsAcrossCalls(): void
    {
        $path = sys_get_temp_dir() . '/snafu-window-' . bin2hex(random_bytes(8)) . '.php';

        try {
            file_put_contents(filename: $path, data: "<?php\n\n\$first = 1;\n");
            $first = $this->context->window($path, 3);

            $this->assertInstanceOf(SourceBlock::class, $first);
            $this->assertSame(
                ['start' => 1, 'end' => 3, 'code' => ['<?php', '', '$first = 1;']],
                $first->jsonSerialize(),
            );

            file_put_contents(filename: $path, data: "<?php\n\n\$second = 2;\n");
            $second = $this->context->window($path, 3);

            $this->assertEquals($first, $second);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function windowFromContents(string $contents, int $line): ?SourceBlock
    {
        $path = sys_get_temp_dir() . '/snafu-window-' . bin2hex(random_bytes(8)) . '.php';

        try {
            file_put_contents(filename: $path, data: $contents);

            return new SourceContext()->window($path, $line);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
