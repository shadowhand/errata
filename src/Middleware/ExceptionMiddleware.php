<?php

declare(strict_types=1);

namespace Snafu\Middleware;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Snafu\Document\Problem;
use Snafu\ExceptionHandlerInterface;
use Throwable;

use function error_log;
use function json_encode;
use function sprintf;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Catches anything the rest of the stack throws and answers with a
 * problem document.
 *
 * @api
 */
final class ExceptionMiddleware implements MiddlewareInterface
{
    private const string CONTENT_TYPE = 'application/problem+json';

    /**
     * Used only when the problem document itself cannot be encoded.
     */
    private const string FALLBACK_BODY = '{"type":"about:blank","title":"Internal Server Error","status":500,"code":0}';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ExceptionHandlerInterface $handler,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $logLevel = LogLevel::ERROR,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $exception) {
            return $this->respond($request, $exception);
        }
    }

    /**
     * Everything past this point runs while an exception is already
     * being reported, so each step is guarded: a failure to report must
     * not become a failure to respond.
     */
    private function respond(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        try {
            $problem = $this->handler->handle($exception);
        } catch (Throwable $failure) {
            error_log(sprintf(
                'snafu: handler failed while reporting %s: %s',
                $exception::class,
                $failure->getMessage(),
            ));

            $problem = Problem::minimal($exception, 500);
        }

        [$status, $body] = self::encode($problem);

        $this->log($request, $exception, $status);

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', self::CONTENT_TYPE);
    }

    private function log(ServerRequestInterface $request, Throwable $exception, int $status): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->log($this->logLevel, $exception->getMessage(), [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'status' => $status,
            ]);
        } catch (Throwable $failure) {
            error_log(sprintf(
                'snafu: logger failed while reporting %s: %s',
                $exception::class,
                $failure->getMessage(),
            ));
        }
    }

    /**
     * @return array{int, string} the HTTP status and the encoded body
     */
    private static function encode(Problem $problem): array
    {
        try {
            $body = json_encode(
                value: $problem,
                flags: JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $failure) {
            error_log(sprintf('snafu: could not encode the problem document: %s', $failure->getMessage()));

            return [500, self::FALLBACK_BODY];
        }

        /** @var string $body */
        return [$problem->status, $body];
    }
}
