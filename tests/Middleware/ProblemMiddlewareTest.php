<?php

declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Closure;
use Errata\Middleware\ProblemMiddleware;
use Errata\Problem;
use Errata\ProblemMap;
use Errata\Tests\Fixtures\TestLogger;
use Errata\ThrowableTransformer;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

use function Psl\Json\decode;

#[CoversClass(ProblemMiddleware::class)]
final class ProblemMiddlewareTest extends TestCase
{
    public function testItReturnsTheHandlerResponseWhenNoThrowableIsThrown(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ProblemMiddleware($factory, $factory);

        $expected = $factory->createResponse(204);
        $handler = self::handler(static fn(): ResponseInterface => $expected);

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame($expected, $response);
    }

    public function testItTransformsAThrowableIntoAProblemResponse(): void
    {
        $factory = new Psr17Factory();
        $middleware = new ProblemMiddleware($factory, $factory);

        $handler = self::handler(static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['title' => 'Internal Server Error', 'status' => 500], decode((string) $response->getBody()));
    }

    public function testItUsesTheStatusFromTheTransformedProblem(): void
    {
        $factory = new Psr17Factory();
        $transformer = new ThrowableTransformer(self::map(
            RuntimeException::class,
            static fn(object $_e): Problem => new Problem(detail: 'Unprocessable.', status: 422),
        ));
        $middleware = new ProblemMiddleware($factory, $factory, $transformer);

        $handler = self::handler(static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['detail' => 'Unprocessable.', 'status' => 422], decode((string) $response->getBody()));
    }

    public function testItFallsBackToAnInternalServerErrorWhenTransformationFails(): void
    {
        $factory = new Psr17Factory();
        $cause = null;
        $transformer = new ThrowableTransformer(self::map(RuntimeException::class, static function (object $_e) use (
            &$cause,
        ): Problem {
            $cause = new LogicException('transform failed');

            throw $cause;
        }));
        $logger = new TestLogger();
        $middleware = new ProblemMiddleware($factory, $factory, $transformer, $logger);

        $handler = self::handler(static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $this->assertSame(['title' => 'Internal Server Error', 'status' => 500], decode((string) $response->getBody()));

        $this->assertSame(2, $logger->calls);
        $this->assertSame(
            [
                'error' => LogicException::class,
                'original' => RuntimeException::class,
                'exception' => $cause,
            ],
            $logger->contextAt(0),
        );
    }

    public function testItReturnsABareInternalServerErrorWhenTheResponseCannotBeCreated(): void
    {
        $factory = new Psr17Factory();
        $responseFactory = new class implements ResponseFactoryInterface {
            public ?RuntimeException $failure = null;

            #[Override]
            public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
            {
                if ($code !== 500) {
                    $this->failure = new RuntimeException('response failed');

                    throw $this->failure;
                }

                return new Psr17Factory()->createResponse($code, $reasonPhrase);
            }
        };
        $problem = new Problem(detail: 'Unprocessable.', status: 422);
        $transformer = new ThrowableTransformer(self::map(
            RuntimeException::class,
            static fn(object $_e): Problem => $problem,
        ));
        $logger = new TestLogger();
        $middleware = new ProblemMiddleware($responseFactory, $factory, $transformer, $logger);

        $throwable = new RuntimeException('boom');
        $handler = self::handler(static function () use ($throwable): ResponseInterface {
            throw $throwable;
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());

        $this->assertSame(2, $logger->calls);
        $this->assertSame(
            [
                'error' => RuntimeException::class,
                'method' => 'GET',
                'uri' => '/',
                'problem' => $problem,
                'exception' => $throwable,
            ],
            $logger->contextAt(0),
        );
        $this->assertSame(
            [
                'error' => RuntimeException::class,
                'exception' => $responseFactory->failure,
            ],
            $logger->contextAt(1),
        );
    }

    public function testItReturnsABareInternalServerErrorWhenTheBodyCannotBeCreated(): void
    {
        $factory = new Psr17Factory();
        $streamFactory = new class implements StreamFactoryInterface {
            public ?RuntimeException $failure = null;

            #[Override]
            public function createStream(string $content = ''): StreamInterface
            {
                $this->failure = new RuntimeException('stream failed');

                throw $this->failure;
            }

            #[Override]
            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new RuntimeException('stream failed');
            }

            #[Override]
            public function createStreamFromResource($resource): StreamInterface
            {
                throw new RuntimeException('stream failed');
            }
        };
        $logger = new TestLogger();
        $middleware = new ProblemMiddleware($factory, $streamFactory, logger: $logger);

        $handler = self::handler(static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', (string) $response->getBody());

        $this->assertSame(2, $logger->calls);
        $this->assertSame(
            [
                'error' => RuntimeException::class,
                'exception' => $streamFactory->failure,
            ],
            $logger->contextAt(1),
        );
    }

    public function testItLogsTheEncounteredThrowable(): void
    {
        $factory = new Psr17Factory();
        $logger = new TestLogger();
        $problem = new Problem(detail: 'Boom.', status: 500);
        $transformer = new ThrowableTransformer(self::map(
            RuntimeException::class,
            static fn(object $_e): Problem => $problem,
        ));
        $middleware = new ProblemMiddleware($factory, $factory, $transformer, $logger);

        $throwable = new RuntimeException('boom');
        $handler = self::handler(static function () use ($throwable): ResponseInterface {
            throw $throwable;
        });

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(1, $logger->calls);
        $this->assertSame('error', $logger->level);
        $this->assertSame(
            [
                'error' => RuntimeException::class,
                'method' => 'GET',
                'uri' => '/',
                'problem' => $problem,
                'exception' => $throwable,
            ],
            $logger->context,
        );
    }

    /**
     * @param class-string $class
     * @param Closure(object):Problem $factory
     */
    private static function map(string $class, Closure $factory): ProblemMap
    {
        return new ProblemMap([$class => $factory]);
    }

    /**
     * @param Closure(): ResponseInterface $respond
     */
    private static function handler(Closure $respond): RequestHandlerInterface
    {
        return new class($respond) implements RequestHandlerInterface {
            /**
             * @param Closure(): ResponseInterface $respond
             */
            public function __construct(
                private readonly Closure $respond,
            ) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->respond)();
            }
        };
    }
}
