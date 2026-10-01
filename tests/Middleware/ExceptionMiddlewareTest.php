<?php

declare(strict_types=1);

namespace Snafu\Tests\Middleware;

use Error;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Snafu\ExceptionHandler;
use Snafu\Middleware\ExceptionMiddleware;
use Snafu\Mode;
use Throwable;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function snafu_fixture_utf8_failure;

require_once __DIR__ . '/../Fixtures/source/utf8_failure.php';

#[CoversClass(ExceptionMiddleware::class)]
final class ExceptionMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    private string $root;

    private string $errorLog;

    #[Override]
    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->root = dirname(path: __DIR__, levels: 2);
        $this->errorLog = $this->root . '/build/phpunit/error.log';

        $directory = dirname(path: $this->errorLog);

        if (!is_dir($directory)) {
            mkdir(directory: $directory, recursive: true);
        }

        file_put_contents(filename: $this->errorLog, data: '');
    }

    public function testItReturnsTheDownstreamResponseUntouched(): void
    {
        $expected = $this->factory->createResponse(204);
        $response = $this->middleware()->process($this->request(), $this->returns($expected));

        $this->assertSame($expected, $response);
    }

    public function testItRespondsWithAFullProblemDocumentOnFailure(): void
    {
        $exception = new RuntimeException('boom', 5);
        $response = $this->middleware(Mode::Full)->process($this->request(), $this->throws($exception));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->document($response);
        /** @var array{detail: string, file: string, line: int, source: string, trace: list<mixed>} $document */

        $this->assertArrayHasKey('detail', $document);
        $this->assertSame('RuntimeException: boom', $document['detail']);
        $this->assertArrayHasKey('file', $document);
        $this->assertSame('tests/Middleware/ExceptionMiddlewareTest.php', $document['file']);
        $this->assertArrayHasKey('line', $document);
        $this->assertSame($exception->getLine(), $document['line']);
        $this->assertArrayHasKey('source', $document);
        $this->assertSame('$exception = new RuntimeException(\'boom\', 5);', $document['source']);
        $this->assertArrayHasKey('trace', $document);
        $this->assertIsArray($document['trace']);
    }

    public function testMinimalOmitsFullMembers(): void
    {
        $response = $this->middleware(Mode::Minimal)->process(
            $this->request(),
            $this->throws(new RuntimeException('boom')),
        );

        $this->assertSame(['type', 'title', 'status', 'code', 'detail'], array_keys($this->document($response)));
    }

    public function testItUsesTheStatusFromTheException(): void
    {
        $response = $this->middleware()->process($this->request(), $this->throws(new MiddlewareStatusFixture()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(422, $this->document($response)['status'] ?? 0);
    }

    public function testItCatchesErrorsAsWellAsExceptions(): void
    {
        $response = $this->middleware()->process($this->request(), $this->throws(new Error('broken')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal Server Error', $this->document($response)['title'] ?? '');
        $this->assertSame('Error', $this->document($response)['detail'] ?? '');
    }

    public function testItLogsTheExceptionWithRequestContext(): void
    {
        $logger = new MiddlewareTestLogger();
        $this->middleware(logger: $logger)->process(
            $this->request(),
            $this->throws(new RuntimeException('boom', 0, null)),
        );

        $this->assertSame(1, $logger->calls);
        $this->assertSame(LogLevel::ERROR, $logger->level);
        $this->assertSame('boom', $logger->message);
        $this->assertSame('GET', $logger->method);
        $this->assertSame('/things', $logger->path);
        $this->assertSame(500, $logger->status);
        $this->assertInstanceOf(RuntimeException::class, $logger->exception);
    }

    public function testItLogsTheDebugLevelWhenConfigured(): void
    {
        $logger = new MiddlewareTestLogger();
        $this->middleware(logger: $logger, logLevel: LogLevel::CRITICAL)->process(
            $this->request(),
            $this->throws(new RuntimeException('boom')),
        );

        $this->assertSame(LogLevel::CRITICAL, $logger->level);
    }

    public function testLoggingIsOptional(): void
    {
        $response = $this->middleware(logger: null)->process(
            $this->request(),
            $this->throws(new RuntimeException('boom')),
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testAThrowingLoggerDoesNotBreakTheResponse(): void
    {
        $response = $this->middleware(logger: new MiddlewareThrowingLogger())->process(
            $this->request(),
            $this->throws(new RuntimeException('boom')),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('RuntimeException', $this->document($response)['detail'] ?? '');
        $this->assertStringContainsString('logger failed', $this->errorLogContents());
    }

    public function testAThrowingHandlerStillProducesAProblemDocument(): void
    {
        $middleware = new ExceptionMiddleware(
            $this->factory,
            new MiddlewareThrowingHandler(),
            new MiddlewareThrowingLogger(),
        );

        $response = $middleware->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(500, $response->getStatusCode());

        $document = $this->document($response);

        $this->assertSame(['type', 'title', 'status', 'code', 'detail'], array_keys($document));
        $this->assertSame('RuntimeException', $document['detail'] ?? '');
        $this->assertStringContainsString('handler failed', $this->errorLogContents());
        $this->assertStringContainsString('logger failed', $this->errorLogContents());
    }

    public function testInvalidUtf8InASourceFileStillProducesDecodableJson(): void
    {
        $exception = $this->utf8Failure();
        $response = $this->middleware(Mode::Full)->process($this->request(), $this->throws($exception));
        $body = (string) $response->getBody();

        $this->assertIsArray($this->document($response));
        $this->assertStringNotContainsString("\xff", $body);
        $this->assertStringContainsString("\u{FFFD}", $body);
    }

    public function testAnUnencodableProblemStillProducesAFallbackBody(): void
    {
        $exception = new RuntimeException('link-0');

        for ($i = 1; $i < 600; $i++) {
            $exception = new RuntimeException('link-' . $i, 0, $exception);
        }

        $response = $this->middleware(Mode::Full)->process($this->request(), $this->throws($exception));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            '{"type":"about:blank","title":"Internal Server Error","status":500,"code":0}',
            (string) $response->getBody(),
        );
        $this->assertStringContainsString('could not encode', $this->errorLogContents());
    }

    public function testAnUnencodableDocumentAnswersWithTheFallbackStatus(): void
    {
        $response = $this->middleware(Mode::Full)->process(
            $this->request(),
            $this->throws(MiddlewareStatusFixture::unencodable()),
        );

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            '{"type":"about:blank","title":"Internal Server Error","status":500,"code":0}',
            (string) $response->getBody(),
        );
    }

    private function middleware(
        Mode $mode = Mode::Minimal,
        ?LoggerInterface $logger = null,
        string $logLevel = LogLevel::ERROR,
    ): ExceptionMiddleware {
        return new ExceptionMiddleware(
            $this->factory,
            new ExceptionHandler($mode, projectDir: $this->root),
            $logger,
            $logLevel,
        );
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://api.example.com/things');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function document(ResponseInterface $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode(json: (string) $response->getBody(), associative: true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function errorLogContents(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function utf8Failure(): Throwable
    {
        try {
            // @mago-ignore analysis:non-existent-function
            snafu_fixture_utf8_failure();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The fixture was expected to throw.');
    }

    private function returns(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function throws(Throwable $exception): RequestHandlerInterface
    {
        return new class($exception) implements RequestHandlerInterface {
            public function __construct(
                private readonly Throwable $exception,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }
}
