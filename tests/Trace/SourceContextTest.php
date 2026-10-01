<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    public function testItReturnsTheReportedLineTrimmed(): void
    {
        $this->assertSame('$alpha = 1;', $this->context->line(self::FIXTURE, 5));
    }

    public function testItReturnsTheFirstAndLastLines(): void
    {
        $this->assertSame('<?php declare(strict_types=1);', $this->context->line(self::FIXTURE, 1));
        $this->assertSame('}', $this->context->line(self::FIXTURE, 13));
    }

    public function testItReturnsNullForAReportedLineOutsideTheFile(): void
    {
        $this->assertNull($this->context->line(self::FIXTURE, 0));
        $this->assertNull($this->context->line(self::FIXTURE, 999));
    }

    public function testItReturnsNullForAnEmptyFile(): void
    {
        $this->assertNull($this->lineFromContents('', 1));
    }

    public function testItReturnsNullForAMissingFile(): void
    {
        $this->assertNull($this->context->line('/nonexistent/snafu/window.php', 1));
    }

    public function testItReturnsNullForADirectory(): void
    {
        $this->assertNull($this->context->line(__DIR__ . '/../Fixtures/source', 1));
    }

    public function testItTrimsWhitespaceAndNormalizesLineSeparators(): void
    {
        $contents = "\t  \r\n\r\n  \$value = 1; \t\r\n \t";

        $this->assertSame('', $this->lineFromContents($contents, 1));
        $this->assertSame('', $this->lineFromContents($contents, 2));
        $this->assertSame('$value = 1;', $this->lineFromContents($contents, 3));
        $this->assertSame('', $this->lineFromContents($contents, 4));
    }

    public function testItReturnsAnEmptyStringForABlankLine(): void
    {
        $this->assertSame('', $this->lineFromContents("\n", 1));
    }

    public function testItCachesFileContentsAcrossCalls(): void
    {
        $path = sys_get_temp_dir() . '/snafu-line-' . bin2hex(random_bytes(8)) . '.php';

        try {
            file_put_contents(filename: $path, data: "<?php\n\n\$first = 1;\n");
            $first = $this->context->line($path, 3);

            $this->assertSame('$first = 1;', $first);

            file_put_contents(filename: $path, data: "<?php\n\n\$second = 2;\n");
            $second = $this->context->line($path, 3);

            $this->assertSame($first, $second);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function lineFromContents(string $contents, int $line): ?string
    {
        $path = sys_get_temp_dir() . '/snafu-line-' . bin2hex(random_bytes(8)) . '.php';

        try {
            file_put_contents(filename: $path, data: $contents);

            return new SourceContext()->line($path, $line);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
