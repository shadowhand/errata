<?php

declare(strict_types=1);

namespace Snafu\Tests\Trace;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Snafu\Document\SourceLine;
use Snafu\Trace\SourceContext;

use function array_map;
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

    public function testItReturnsTheFiveLineWindowAroundTheLine(): void
    {
        $window = $this->context->window(self::FIXTURE, 5);

        $this->assertSame(
            [
                ['line' => 3, 'code' => 'function snafu_fixture_alpha(): void'],
                ['line' => 4, 'code' => '{'],
                ['line' => 5, 'code' => '    $alpha = 1;'],
                ['line' => 7, 'code' => '    $beta = 2;'],
            ],
            self::json($window),
        );
    }

    public function testItClampsTheWindowAtTheStartOfTheFile(): void
    {
        $window = $this->context->window(self::FIXTURE, 1);

        $this->assertSame([1, 3], array_map(static fn(SourceLine $line): int => $line->line, $window));
    }

    public function testItClampsTheWindowAtTheEndOfTheFile(): void
    {
        $window = $this->context->window(self::FIXTURE, 13);

        $this->assertSame([11, 12, 13], array_map(static fn(SourceLine $line): int => $line->line, $window));
    }

    public function testItReturnsNothingForALinePastTheEndOfTheFile(): void
    {
        $this->assertSame([], $this->context->window(self::FIXTURE, 999));
    }

    public function testItReturnsNothingForALineBeforeTheStartOfTheFile(): void
    {
        $this->assertSame([], $this->context->window(self::FIXTURE, 0));
    }

    public function testItReturnsNothingForAMissingFile(): void
    {
        $this->assertSame([], $this->context->window('/nonexistent/snafu/window.php', 1));
    }

    public function testItReturnsNothingForADirectory(): void
    {
        $this->assertSame([], $this->context->window(__DIR__ . '/../Fixtures/source', 1));
    }

    public function testItCachesFileContentsAcrossCalls(): void
    {
        $path = sys_get_temp_dir() . '/snafu-window-' . bin2hex(random_bytes(8)) . '.php';

        try {
            file_put_contents(filename: $path, data: "<?php\n\n\$first = 1;\n");
            $first = $this->context->window($path, 3);

            $this->assertSame(
                [
                    ['line' => 1, 'code' => '<?php'],
                    ['line' => 3, 'code' => '$first = 1;'],
                ],
                self::json($first),
            );

            file_put_contents(filename: $path, data: "<?php\n\n\$second = 2;\n");
            $second = $this->context->window($path, 3);

            $this->assertSame(self::json($first), self::json($second));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param list<SourceLine> $lines
     *
     * @return list<array<string, mixed>>
     */
    private static function json(array $lines): array
    {
        $encoded = [];

        foreach ($lines as $line) {
            $encoded[] = $line->jsonSerialize();
        }

        return $encoded;
    }
}
