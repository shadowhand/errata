# Middleware

`Errata\Middleware\ProblemMiddleware` is a PSR-15 middleware that turns any throwable thrown by the inner stack
into an [RFC 9457][] problem response.

[RFC 9457]: https://www.rfc-editor.org/rfc/rfc9457

## Usage

Register it as the outermost middleware so it sees everything the rest of the stack throws.

```php
use Errata\Middleware\ProblemMiddleware;
use Errata\ThrowableTransformer;

// Configure which throwables/exceptions map to which problems,
// and what extensions should be enabled.
$transformer = new ThrowableTransformer();

$middleware = new ProblemMiddleware(
    $responseFactory,    // any PSR-17 ResponseFactoryInterface
    $streamFactory,      // any PSR-17 StreamFactoryInterface
    $transformer,        // optional throwable transformer
    $logger,             // optional PSR-3 logger
);
```

The `ThrowableTransformer` can be [configured](throwables.md) with a `ProblemMap` and an `ExtensionList`.

## Behavior

When the inner stack returns a response, it passes through unchanged.

When the inner stack throws:

1. The throwable is transformed into a [Problem](problems.md) (see [Transforming Throwables](throwables.md)). If
   the resulting problem has no status, it is given `500`.
2. A response is built with the problem's status, a `Content-Type` of `application/problem+json`, and the JSON
   encoded problem as the body.

## Failure fallback

Conversion cannot itself take down the middleware. There are two failure paths, each of which returns a bare `500`
response without a body:

- If the throwable cannot be transformed, the middleware falls back to an `InternalServerError`.
- If the response or body cannot be built, the middleware returns a plain `500` response.

Both failures are reported to the logger when one is supplied.

## Logging

When a logger is supplied, every caught throwable is logged at `error` level.

The primary failure is logged with:

- `error` is the throwable class name;
- `method` and `uri` are the request method and the full request URI, including any query string;
- `problem` is the resolved problem document;
- `exception` is the throwable, under the conventional context key.

A failure while transforming the throwable also logs `original`, the originally caught throwable class. A failure
while building the response logs only `error` and `exception`.

Request headers and bodies are never logged.
