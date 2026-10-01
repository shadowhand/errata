# Snafu

♞♘ Exceptional error handler for JSON APIs.

Turns any uncaught `Throwable` into an RFC 9457 `application/problem+json` document, logs it through PSR-3, and
never leaks production internals.

## Installation

```sh
composer require snafu/snafu
```

## Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Snafu\Mode;
use Snafu\ExceptionHandler;
use Snafu\Middleware\ExceptionMiddleware;

$middleware = new ExceptionMiddleware(
    new Psr17Factory(),
    new ExceptionHandler(Mode::fromEnv()),
    $logger,   // optional PSR-3 logger; omit to disable logging
);
```

Register it as the outermost PSR-15 middleware so it sees everything the rest of the stack throws.

## Modes

`Mode::fromEnv()` reads `APP_ENV`, then `APP_DEBUG`:

| `APP_ENV` | `APP_DEBUG` | Mode |
|-----------|-------------|------|
| `dev`, `development`, `local` | any | full |
| anything else | `1`, `true`, `on`, `yes` | full |
| anything else | anything else | minimal |

Anything unrecognised, including unset variables, is minimal: a mode that leaks internals is never chosen by
accident. Pass a `Mode` case explicitly to bypass detection.

`title` is the recommended HTTP status phrase for the document's status, as RFC 9457 §4.2.1 requires when `type`
is `about:blank`. The exception identity travels in `detail`: minimal responses carry the short exception class
name, and full responses set `detail` to `class: message`.

Minimal responses:

```json
{"type":"about:blank","title":"Internal Server Error","status":500,"code":0,"detail":"RuntimeException"}
```

Full responses add `file`, `line`, `source`, `trace`, and, for chained exceptions, `previous`.

## Traces

Paths are relative to the application directory, which defaults to the Composer root package directory and can be
overridden with the second `ExceptionHandler` argument.

The exception's origin and every frame carry a five-line source window (the reported line ±2), clamped to the file,
with blank lines removed and original line numbers preserved. Traces are capped at 30 frames (`traceLimit`), keeping
the frames nearest the throw; a capped trace is flagged with `traceTruncated`.

Frame arguments are included, truncated to depth 5, 50 items, and 500 bytes per string. Objects are reduced to a
class name plus at most 50 public properties, with the remainder reported by the same `"*truncated*": "N more
items"` marker used for maps. `#[\SensitiveParameter]` values are redacted, and `__toString()` is never called.
A frame whose arguments PHP did not report carries no `args` member at all.

**Arguments require `zend.exception_ignore_args=Off`.** It defaults to `Off`, and `php.ini-development` sets
`Off`, but `php.ini-production` sets `On`, which removes arguments from every trace PHP produces. An application
running full mode with a production `php.ini` will see frames with no `args` member.

## Custom status codes

Implement `Snafu\Http\StatusCodeInterface` to control the HTTP status:

```php
use Snafu\Http\StatusCodeInterface;

final class NotFound extends RuntimeException implements StatusCodeInterface
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
```

Values outside 400..599 are ignored in favour of 500.

## Logging

Every caught throwable is logged at `LogLevel::ERROR` by default (the level is the fourth `ExceptionMiddleware`
argument) with the throwable under the conventional `exception` key, plus `method`, `path`, and `status`. Headers,
query strings, and bodies are never logged. A logger that itself throws is reported with `error_log()` and the
response is still sent.

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
