<?php

declare(strict_types=1);

namespace Errata\Middleware;

use Errata\Http\Server\InternalServerError;
use Errata\Problem;
use Errata\ThrowableTransformer;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function Psl\Json\encode;

final readonly class ProblemMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ThrowableTransformer $transformer = new ThrowableTransformer(),
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            $problem = $this->transform($throwable);

            // Default status SHOULD be 500 Internal Server Error.
            $problem->status ??= 500;

            $this->logger?->error('[Errata] Encountered {error} while handling {method} {uri}', [
                'error' => $throwable::class,
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'problem' => $problem,
                'exception' => $throwable,
            ]);

            return $this->respond($problem);
        }
    }

    private function transform(Throwable $throwable): Problem
    {
        try {
            return $this->transformer->transform($throwable);
        } catch (Throwable $secondary) {
            $this->logger?->error('[Errata] Encountered {error} while transforming {original}', [
                'error' => $secondary::class,
                'original' => $throwable::class,
                'exception' => $secondary,
            ]);

            return new InternalServerError();
        }
    }

    private function respond(Problem $problem): ResponseInterface
    {
        try {
            // @mago-expect analysis:possibly-null-argument
            $response = $this->responseFactory->createResponse($problem->status);
            $response = $response->withHeader('Content-Type', 'application/problem+json');

            $body = $this->streamFactory->createStream(encode($problem));

            return $response->withBody($body);
        } catch (Throwable $throwable) {
            $this->logger?->error('[Errata] Encountered {error} while creating response', [
                'error' => $throwable::class,
                'exception' => $throwable,
            ]);

            return $this->responseFactory->createResponse(500);
        }
    }
}
