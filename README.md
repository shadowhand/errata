<div style="text-align:center;margin:0 auto;">
    <img src="docs/errata-banner.jpg" style="width:100%;max-width:1200px" alt="Errata Banner"/>
</div>

# Errata

Exceptionally safe error handling for JSON APIs using API Problem ([RFC 9457][]) objects.

[RFC 9457]: https://www.rfc-editor.org/rfc/rfc9457

## Installation

```sh
composer require errata/errata
```

## Quick start

`ProblemMiddleware` is a PSR-15 middleware. Register it as the outermost middleware so it sees everything the rest of
the stack throws.

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

A response returned by the inner stack passes through unchanged. When the inner stack throws, the throwable is
converted to a problem and returned as an `application/problem+json` response.

## Documentation

- [Problems](docs/problems.md) covers the `Problem` document and the fixed-status HTTP problems.
- [Transforming Throwables](docs/throwables.md) covers how a `Throwable` becomes a problem and the built-in
  extensions.
- [Middleware](docs/middleware.md) covers the middleware's behavior, failure fallback, and logging.

## Development

This project uses [Mago](https://mago.carthage.software/) for lint, formatting, and static analysis.

```
composer run fix       # automatically fix lint, analysis, and formatting issues
composer run format    # format source code
composer run check     # check style
composer run lint      # lint code
composer run analyze   # static analysis
composer run test      # unit testing with 100% coverage enforced
composer run verify    # run all verifications (check + lint + analyze + test)
```
