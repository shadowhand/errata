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

**Register it as the outermost PSR-15 middleware so it sees everything the rest of the stack throws.**

## Modes

`Mode::fromEnv()` reads `APP_ENV`, then `APP_DEBUG`:

- If `APP_ENV` is `dev`, `development`, or `local` → **Full**
- If `APP_DEBUG` evaluates as `true` (is `on`, `yes`, `1`, etc) → **Full**
- Otherwise → **Minimal**

Anything unrecognised, including unset variables, is **Minimal**. Pass a `Mode` case explicitly to bypass detection.

Minimal responses:

```json
{"type":"about:blank","title":"Internal Server Error","status":500,"code":0,"detail":"RuntimeException"}
```

Full responses add `file`, `line`, `source`, `trace`, and, for chained exceptions, `previous`. Full responses will
also be pretty printed.

`title` is the recommended HTTP status phrase for the document's status, as RFC 9457 §4.2.1 requires when `type`
is `about:blank`. The exception identity travels in `detail`: minimal responses carry the short exception class
name, and full responses set `detail` to `class: message`.

## Traces

Paths are relative to the application directory, which defaults to the Composer root package directory and can be
overridden with the second `ExceptionHandler` argument. Paths embedded in PHP closure function names and anonymous
class names are also made relative when they point inside the application directory.

The exception's origin and every frame carry one `source` string: the source line at the reported `line`, trimmed
of surrounding whitespace. A blank line is an empty string. Unavailable source is omitted. Traces are capped at
30 frames (`traceLimit`), keeping the frames nearest the throw; a capped trace is flagged with `truncated`.

Frame arguments are reduced to their types, never their values: `args` is a list of type names such as `int`,
`string`, `bool`, `null`, `float`, `Closure`, a class name, or `resource (stream)`. An array is `vec` when it is a
list and `dict` otherwise. A `#[\SensitiveParameter]` argument reports the type of the protected value, never the
value. A frame whose arguments PHP did not report carries no `args` member at all.

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

Open <http://localhost:8000/> for a centered grid of links. The same router serves all five demos, each intentionally
returning a 500 `application/problem+json` response through `ExceptionMiddleware`:

- `/minimal`: production-style output without the exception message, source, or trace.
- `/full`: the same exception with development details.
- `/recursion`: bounded recursive calls that produce a truncated trace.
- `/sensitive`: a `#[\SensitiveParameter]` argument reduced to its type, never its value.
- `/types`: a call with scalar, `vec`, `dict`, object, enum, and closure arguments, each shown as its type.

You can also request a demo directly, for example with `curl -i http://localhost:8000/full`.

Development demos enable exception arguments regardless of your `php.ini`. The sensitive demo accepts optional
`DEMO_PASSWORD` and `DEMO_TOKEN` environment variables; its defaults are fake credentials. Because traces carry
argument types only, no argument value ever reaches the response; `#[\SensitiveParameter]` is still respected.
Never put real secrets in exception messages or source files.

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
