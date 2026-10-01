<?php

declare(strict_types=1);

namespace Errata\Tests;

use Errata\Document\Problem;
use Errata\Document\Trace;
use Errata\ExceptionHandler;
use Errata\Mode;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

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

    public function testFullCarriesTheMessageLocationAndSourceLine(): void
    {
        $exception = new RuntimeException('boom');
        $problem = $this->handler(Mode::Full)->handle($exception);
        $this->assertSame('RuntimeException: boom', $problem->detail);
        $this->assertSame('tests/ExceptionHandlerTest.php', $problem->file);
        $this->assertSame($exception->getLine(), $problem->line);
        $this->assertSame('$exception = new RuntimeException(\'boom\');', $problem->source);
        $document = $this->document($problem);
        /** @var array{source: string} $document */
        $this->assertArrayHasKey('source', $document);
        $this->assertSame($problem->source, $document['source']);
        $this->assertInstanceOf(Trace::class, $problem->trace);
        $this->assertStringNotContainsString('truncated', (string) json_encode($problem));
        $this->assertNull($problem->previous);
    }

    public function testItUsesTheStatusCodeInterfaceOnlyWhenTheStatusIsInRange(): void
    {
        $handler = $this->handler(Mode::Minimal);

        $this->assertSame(404, $handler->handle(new ExceptionHandlerStatusFixture(404))->status);
        $this->assertSame(500, $handler->handle(new ExceptionHandlerStatusFixture(200))->status);
        $this->assertSame(500, $handler->handle(new ExceptionHandlerStatusFixture(600))->status);
    }

    public function testMinimalOmitsTheCauseButFullNestsItWithSourceLines(): void
    {
        $exception = $this->chainedFailure();

        $this->assertNull($this->handler(Mode::Minimal)->handle($exception)->previous);

        $problem = $this->handler(Mode::Full)->handle($exception);
        $previous = $problem->previous;

        $this->assertInstanceOf(Problem::class, $previous);
        $this->assertSame('LogicException: inner', $previous->detail);
        $this->assertSame('Internal Server Error', $previous->title);
        $this->assertSame('tests/ExceptionHandlerTest.php', $previous->file);

        $cause = $exception->getPrevious();

        $this->assertInstanceOf(LogicException::class, $cause);
        $this->assertSame($cause->getLine(), $previous->line);
        $this->assertSame('return new RuntimeException(\'outer\', 0, $previous);', $problem->source);
        $this->assertSame('$previous = new LogicException(\'inner\');', $previous->source);

        $serializedProblem = $this->document($problem);
        /** @var array{previous: array<string, mixed>} $serializedProblem */
        $this->assertArrayHasKey('previous', $serializedProblem);
        $serializedPrevious = $serializedProblem['previous'];

        $this->assertIsArray($serializedPrevious);
        $this->assertSame($this->document($previous), $serializedPrevious);

        $missingSource = new class($this->root . '/missing-source.php') extends RuntimeException {
            public function __construct(string $file)
            {
                parent::__construct('unavailable');
                $this->file = $file;
                $this->line = 1;
            }
        };
        $unavailable = $this->handler(Mode::Full)->handle($missingSource);

        $this->assertSame('missing-source.php', $unavailable->file);
        $this->assertSame(1, $unavailable->line);
        $this->assertNull($unavailable->source);
        $this->assertArrayNotHasKey('source', $this->document($unavailable));
        $this->assertInstanceOf(Trace::class, $unavailable->trace);
        $this->assertNotEmpty($unavailable->trace->frames);

        $outer = $this->handler(Mode::Full)->handle(new RuntimeException('outer', 0, $missingSource));
        $unavailablePrevious = $outer->previous;

        $this->assertIsString($outer->source);
        $this->assertInstanceOf(Problem::class, $unavailablePrevious);
        $this->assertNull($unavailablePrevious->source);
        $serializedOuter = $this->document($outer);
        /** @var array{previous: array<string, mixed>} $serializedOuter */
        $this->assertArrayHasKey('previous', $serializedOuter);
        $serializedPrevious = $serializedOuter['previous'];

        $this->assertIsArray($serializedPrevious);
        $this->assertArrayNotHasKey('source', $serializedPrevious);
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

    private function chainedFailure(): RuntimeException
    {
        $previous = new LogicException('inner');

        return new RuntimeException('outer', 0, $previous);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(Problem $problem): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode(
            json: json_encode($problem, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function handler(Mode $mode, int $traceLimit = 30): ExceptionHandler
    {
        return new ExceptionHandler($mode, projectDir: $this->root, traceLimit: $traceLimit);
    }
}
