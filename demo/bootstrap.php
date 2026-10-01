<?php

declare(strict_types=1);

namespace Snafu\Demo;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Snafu\ExceptionHandler;
use Snafu\Middleware\ExceptionMiddleware;
use Snafu\Mode;

use function dirname;
use function header;
use function http_response_code;
use function ini_set;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param Closure(): never $action
 *
 * @internal
 */
function run_demo(Mode $mode, Closure $action): void
{
    ini_set(option: 'display_errors', value: '0');
    ini_set(option: 'zend.exception_ignore_args', value: $mode === Mode::Full ? '0' : '1');

    $factory = new Psr17Factory();
    $middleware = new ExceptionMiddleware($factory, new ExceptionHandler($mode));
    $handler = new class($action) implements RequestHandlerInterface {
        public function __construct(
            /** @var Closure(): never */
            private readonly Closure $action,
        ) {}

        #[Override]
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            ($this->action)();
        }
    };

    $request = $factory->createServerRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
    $response = $middleware->process($request, $handler);

    http_response_code($response->getStatusCode());

    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header(header: $name . ': ' . $value, replace: false);
        }
    }

    echo (string) $response->getBody();
}
