# Errata

📜 Exceptional error handler for JSON APIs.

Turns any uncaught `Throwable` into an RFC 9457 `application/problem+json` document, logs it through PSR-3, and
never leaks production internals.

## Installation

```sh
composer require errata/errata
```

## Usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Errata\Mode;
use Errata\ExceptionHandler;
use Errata\Middleware\ExceptionMiddleware;

$middleware = new ExceptionMiddleware(
    new Psr17Factory(), // any PSR-17 ResponseFactoryInterface may be used
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
overridden with the second `ExceptionHandler` argument. Paths embedded in PHP closure function names and anonymous
class names are also made relative when they point inside the application directory.

The exception's origin and every frame carry one `source` string: the source line at the reported `line`, trimmed
of surrounding whitespace. A blank line is an empty string. Unavailable source is omitted. Traces are capped at
30 frames (`traceLimit`), keeping the frames nearest the throw; a capped trace is flagged with `traceTruncated`.

Frame arguments are included, truncated to depth 5, 50 items, and 500 bytes per string. Objects are reduced to a
class name plus at most 50 public properties, with the remainder reported by the same `"*truncated*": "N more
items"` marker used for maps. `#[\SensitiveParameter]` values are redacted, and `__toString()` is never called.
A frame whose arguments PHP did not report carries no `args` member at all.

**Arguments require `zend.exception_ignore_args=Off`.** It defaults to `Off`, and `php.ini-development` sets
`Off`, but `php.ini-production` sets `On`, which removes arguments from every trace PHP produces. An application
running full mode with a production `php.ini` will see frames with no `args` member.

## Custom status codes

Implement `Errata\Http\StatusCodeInterface` to control the HTTP status:

```php
use Errata\Http\StatusCodeInterface;

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

## Demos

After running `composer install` (including development dependencies), start the demo router from the repository root:

```sh
php -S localhost:8000 demo/index.php
```

Open <http://localhost:8000/> for a centered grid of links. The same router serves all four demos, each intentionally
returning a 500 `application/problem+json` response through `ExceptionMiddleware`:

- `/minimal`: production-style output without the exception message, source, or trace.
- `/full`: the same exception with development details.
- `/recursion`: bounded recursive object calls with cyclic public properties and a truncated trace.
- `/sensitive`: redacted parameter arguments and public properties wrapped in `SensitiveParameterValue`.

You can also request a demo directly, for example with `curl -i http://localhost:8000/full`.

Development demos enable exception arguments regardless of your `php.ini`. The sensitive demo accepts optional
`DEMO_PASSWORD` and `DEMO_TOKEN` environment variables; its defaults are fake credentials. `#[SensitiveParameter]`
protects trace arguments, not stored properties, exception messages, or source code. Use `SensitiveParameterValue`
for sensitive public properties and never put real secrets in messages or source files.

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
