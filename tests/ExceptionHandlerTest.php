<?php

declare(strict_types=1);

namespace Snafu\Tests;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Snafu\Document\Problem;
use Snafu\Document\Trace;
use Snafu\ExceptionHandler;
use Snafu\Mode;

use function dirname;
use function json_encode;

#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerTest extends TestCase
{
    private string $root;

    #[Override]
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testMinimalGivesTheStatusPhraseTheShortClassNameAndTheCode(): void
    {
        $problem = $this->handler(Mode::Minimal)->handle(new RuntimeException('secret detail', 7));

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'code' => 7,
                'detail' => 'RuntimeException',
            ],
            $problem->jsonSerialize(),
        );
    }

    public function testMinimalNeverLeaksTheMessageOrLocation(): void
    {
        $json = json_encode($this->handler(Mode::Minimal)->handle(new RuntimeException('secret detail')));

        $this->assertIsString($json);
        $this->assertStringNotContainsString('secret detail', $json);
        $this->assertStringNotContainsString('ExceptionHandlerTest', $json);
    }

    public function testFullCarriesTheMessageLocationAndWindow(): void
    {
        $problem = $this->handler(Mode::Full)->handle(new RuntimeException('boom'));

        $this->assertSame('RuntimeException: boom', $problem->detail);
        $this->assertSame('tests/ExceptionHandlerTest.php', $problem->file);
        $this->assertIsInt($problem->line);
        $this->assertNotSame([], $problem->source);
        $this->assertInstanceOf(Trace::class, $problem->trace);
        $this->assertStringNotContainsString('traceTruncated', (string) json_encode($problem));
        $this->assertNull($problem->previous);
    }

    public function testItUsesTheStatusCodeInterfaceWhenInRange(): void
    {
        $problem = $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(404));

        $this->assertSame(404, $problem->status);
    }

    public function testItFallsBackToInternalServerErrorForATooLowStatus(): void
    {
        $this->assertSame(500, $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(200))->status);
    }

    public function testItFallsBackToInternalServerErrorForATooHighStatus(): void
    {
        $this->assertSame(500, $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(600))->status);
    }

    public function testMinimalOmitsTheCauseButFullNestsIt(): void
    {
        $exception = new RuntimeException('outer', 0, new LogicException('inner'));

        $this->assertNull($this->handler(Mode::Minimal)->handle($exception)->previous);

        $previous = $this->handler(Mode::Full)->handle($exception)->previous;

        $this->assertInstanceOf(Problem::class, $previous);
        $this->assertSame('LogicException: inner', $previous->detail);
        $this->assertSame('Internal Server Error', $previous->title);
    }

    public function testItNestsEveryLinkOfALongCauseChain(): void
    {
        $exception = new RuntimeException('link-0');

        for ($i = 1; $i < 6; $i++) {
            $exception = new RuntimeException('link-' . $i, 0, $exception);
        }

        $problem = $this->handler(Mode::Full)->handle($exception);
        $links = 1;

        while ($problem->previous instanceof Problem) {
            $problem = $problem->previous;
            $links++;
        }

        $this->assertSame(6, $links);
        $this->assertSame('RuntimeException: link-0', $problem->detail);
    }

    public function testItResolvesStatusesInsideTheChainIndependently(): void
    {
        $exception = new RuntimeException('outer', 0, new ExceptionHandlerStatusFixture(409));

        $problem = $this->handler(Mode::Full)->handle($exception);

        $this->assertSame(500, $problem->status);
        $this->assertSame(409, $problem->previous?->status);
    }

    public function testItClampsANegativeTraceLimit(): void
    {
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $exception) {
            $problem = $this->handler(Mode::Full, traceLimit: -1)->handle($exception);
        }

        $this->assertSame([], $problem->trace?->frames);
        $this->assertTrue($problem->trace->truncated);
    }

    private function handler(Mode $mode, int $traceLimit = 30): ExceptionHandler
    {
        return new ExceptionHandler($mode, projectDir: $this->root, traceLimit: $traceLimit);
    }
}
