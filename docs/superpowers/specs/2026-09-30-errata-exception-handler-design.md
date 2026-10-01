# Errata — exception handler for JSON APIs

Date: 2026-09-30
Status: approved in brainstorming; pending review of this document

## Source-context amendments — 2026-10-01

The source contract is `{line: int, source: string}`. The outer `line` is the reported line; `source` is that
one physical source line, trimmed of surrounding whitespace. Unavailable source is omitted.

Earlier amendments carried a `{start, end, code}` block covering the reported line ±3, first as one multiline
string and then as a line array. The surrounding lines proved unhelpful and the nested object read poorly in
JSON, so this amendment reduces `source` to the single reported line. Each amendment changes the existing
response contract; no compatibility format is retained. The sections below describe the current contract, and
the implementation follows it.

## Argument-type amendments — 2026-10-01

Trace frame arguments are reported as types, never values. `args` is a list of type names: `get_debug_type()` for
every value, except arrays, which are `vec` when `array_is_list()` is true and `dict` otherwise. A
`SensitiveParameterValue` is unwrapped so the trace reports the type of the protected value, never the value.

This replaces the earlier argument sanitizer, which emitted truncated values, object property maps, and redaction
markers. Because no value ever reaches the trace, there is nothing to truncate, no cycle to detect, no user code
to avoid invoking, and no redaction to perform; `SanitizedMap`, `SanitizedObject`, and `ArgumentSanitizer` are
removed. The sections below describe the current contract, and the implementation follows it.

## Purpose

A small library that turns any uncaught `Throwable` in a JSON API into a
well-formed RFC 9457 problem document, logs it through PSR-3, and never
leaks production internals. Its unit of integration is a PSR-15
middleware; its unit of logic is a handler that maps `Throwable` to a
`Problem` value object.

Success criteria:

- A caught exception yields `application/problem+json` with an HTTP
  status and a body, in both modes, always.
- Minimal bodies contain the status phrase from
  `codeinc/http-reason-phrase-lookup`'s
  `HttpReasonPhraseLookup::getReasonPhrase()` (or the code itself when
  there is no phrase there), the
  short exception class name in `detail`, and the exception code, and
  nothing else that could leak internals.
- Full bodies contain `detail` as `class: message`, the origin
  `file`/`line`/`source`, and a frame list, each with its source line.
- No code path in the middleware can itself throw, including when
  logging fails or when JSON encoding fails.

## Requirements (given)

1. All output is JSON.
2. A PSR-15 middleware is included.
3. All exceptions are logged via PSR-3.
4. A minimal mode and a full mode.
5. Minimal: status phrase from `codeinc/http-reason-phrase-lookup`'s
   `HttpReasonPhraseLookup::getReasonPhrase()`, short exception class
   name in `detail`, and code only.
6. Full: additionally `detail` as `class: message`, file, line, trace.
7. Trace paths are relative to the application directory.
8. Context is the source line at the reported line, for the origin and
   for every frame alike.
9. Context is one source line, trimmed of surrounding whitespace.

## Decisions

Decisions made during brainstorming, with the reasoning that matters for
later changes.

| # | Decision | Choice |
|---|----------|--------|
| 1 | Body format | RFC 9457 problem document, `application/problem+json` |
| 2 | `type` member | Always `about:blank`; `title` is the recommended status phrase, from `codeinc/http-reason-phrase-lookup`'s `HttpReasonPhraseLookup::getReasonPhrase()` (RFC 9457 §4.2.1) |
| 3 | Minimal body | Full-only members omitted entirely, never `null`; `detail` is the short class name |
| 4 | Class structure | Separate `ExceptionHandler` + `ExceptionMiddleware` |
| 5 | Logging | `LoggerInterface` optional (null disables), level configurable, default `LogLevel::ERROR` |
| 6 | Trace frame shape | Reported `line` plus one `source` string: the source line at that line |
| 7 | Vendor detection | None: no vendor flag, no vendor-directory config; every frame is treated identically |
| 8 | Frame order | Innermost-first — PHP's own `getTrace()` order, nothing reversed |
| 9 | Frame arguments | Included as types only; `#[\SensitiveParameter]` unwrapped to the protected value's type; omitted when PHP does not report them |
| 10 | Path base | Composer-derived default, individually overridable |
| 11 | Trace length | Capped, default 30 frames, keeps the innermost frames |
| 12 | Mode source | `APP_ENV`, then `APP_DEBUG`, then Minimal |
| 13 | Non-500 status | Opt-in `StatusCodeInterface`; interface only, no shipped exception classes |
| 14 | `code` member | `(int) $exception->getCode()` |
| 15 | Handler return | `Problem` value object (`JsonSerializable`) |
| 16 | Argument representation | Types only: `get_debug_type()`, with arrays split into `vec`/`dict` |
| 17 | Chained exceptions | Recursed in full mode, omitted in minimal mode |
| 18 | Logger failure | Caught, reported with `error_log()`, processing continues |
| 19 | Log context | `exception`, `method`, `path`, `status` |
| 20 | Objects in arguments | Class name only |
| 21 | Origin frame | Top-level `file`/`line`/`source` only; not duplicated into `trace` |
| 22 | Response headers | `Content-Type: application/problem+json` only |
| 23 | Source context | The source line at the reported line, origin and frames alike, trimmed of surrounding whitespace |
| 24 | Document types | DTOs implementing `JsonSerializable`; bare arrays only for lists |
| 25 | Map payloads | None: arguments carry no values, so no map DTO exists |
| 26 | API markers | Document DTOs are `@api`; collaborator classes are `@internal` |
| 27 | Handler seam | `ExceptionHandlerInterface`, implemented by `ExceptionHandler`, is what `ExceptionMiddleware` depends on |
| 28 | `title` / `detail` | `title` is the phrase for the status from `codeinc/http-reason-phrase-lookup`'s `HttpReasonPhraseLookup::getReasonPhrase()`, or the code when no phrase is registered; `detail` carries the exception identity — short class name in minimal, `class: message` in full |

Known, accepted consequences:

- Decision 14 flattens `PDOException`'s SQLSTATE string code to `0`.
  Preserving it would make the member a `int|string` union.
- Decision 8 keeps PHP's own order, which is already innermost-first;
  clients must still not assume `getTrace()` ordering across PHP
  versions.
- Decision 24 cannot reach PHP's own boundary: `jsonSerialize()` itself
  must return an `array`, and `Throwable::getTrace()` hands over an
  array. Arrays are therefore used at those two edges by necessity, and
  as list payloads throughout. Every *structural* shape — anything with a
  fixed, named set of members — is a DTO.
- Decision 27 is what makes the middleware's handler-failure guard
  testable at all. Every collaborator is `final`, so no test double can
  reach `ExceptionHandler`; without an interface for the middleware to
  depend on, that branch would be unreachable and the 100% coverage gate
  would fail. The seam exists for the test, and the guard exists for
  robustness — neither works without the other.

## Package layout

```
src/
  Mode.php                               enum Mode: string
  ExceptionHandler.php                   final class, implements ExceptionHandlerInterface
  ExceptionHandlerInterface.php          interface
  Http/StatusCodeInterface.php           interface
  Middleware/ExceptionMiddleware.php     final class, PSR-15
  Document/Problem.php                   final readonly, JsonSerializable
  Document/Trace.php                     final readonly, JsonSerializable
  Document/Frame.php                     final readonly, JsonSerializable
  Path/PathRelativizer.php               final class
  Trace/TraceFactory.php                 final class
  Trace/SourceContext.php                final class
  Trace/ArgumentTyper.php                final class
tests/
  Fixtures/                              source files and traces used by tests
```

`Document/` holds every type that appears in the response body, because
that namespace *is* the JSON contract. `Trace/` holds the plumbing that
produces it.

Every class is `final` (mago `enforce-class-finality`). Document DTOs,
`Mode`, `ExceptionHandler`, `ExceptionHandlerInterface`,
`ExceptionMiddleware`, and `StatusCodeInterface` are marked `@api`;
`Path/`, `Trace/`, and the internal members of the rest are marked
`@internal` (mago `require-api-or-internal`). `Problem` is `@api`
because it is the return type of `ExceptionHandlerInterface::handle()`
and holds `Trace` and `Frame`; marking it internal would
put an internal type in a public signature without making it
inaccessible.

Requires PHP 8.4 (`composer.json` is the single source of the version; CI
derives it from there), so no PHP 8.5 API is used. All nullable
parameters use explicit `?T` syntax, since PHP 8.4 deprecates implicit
nullable parameters.

## Public API

```php
/** @api */
enum Mode: string
{
    case Full = 'full';
    case Minimal = 'minimal';

    public static function fromEnv(): self;
}

namespace Errata\Http;

/** @api */
interface StatusCodeInterface
{
    public function getStatusCode(): int;
}
```

```php
/** @api */
interface ExceptionHandlerInterface
{
    public function handle(Throwable $exception): Problem;
}

/** @api */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        private Mode $mode,
        private ?string $projectDir = null,
        private int $traceLimit = 30,
    ) {}

    #[Override]
    public function handle(Throwable $exception): Problem;
}
```

```php
/** @api */
final class ExceptionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private ExceptionHandlerInterface $handler,
        private ?LoggerInterface $logger = null,
        private string $logLevel = LogLevel::ERROR,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
}
```

Document DTOs, all readonly and `JsonSerializable`, all constructed with
named arguments. `#[Override]` marks `jsonSerialize()` on each, matching
mago's `check-missing-override`:

```php
/** @api */
final readonly class Problem implements JsonSerializable
{
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public int $code,
        public ?string $detail = null,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $source = null,
        public ?Trace $trace = null,
        public ?Problem $previous = null,
    ) {}

    /**
     * The minimal document: the status phrase, the short class name as
     * `detail`, and the code.
     */
    public static function minimal(Throwable $exception, int $status): self;

    /**
     * The full document: `minimal()` plus `detail` as `class: message`,
     * the origin, the source line, the trace, and the cause.
     */
    public static function development(
        Throwable $exception,
        int $status,
        string $file,
        int $line,
        ?string $source,
        Trace $trace,
        ?Problem $previous,
    ): self;

    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class Trace implements JsonSerializable
{
    public function __construct(
        /** @var list<Frame> */
        public array $frames,
        public bool $truncated,
    ) {}

    /** @return list<Frame> */
    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class Frame implements JsonSerializable
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $function = null,
        public ?string $class = null,
        public ?string $type = null,
        /** @var list<string>|null */
        public ?array $args = null,
        public ?string $source = null,
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}
```

Serialization rules:

- `Problem::jsonSerialize()` emits members in this order:
  `type`, `title`, `status`, `code`, `detail`, `file`, `line`, `source`,
  `trace`, `truncated`, `previous`. `title` is the status phrase
  (from `codeinc/http-reason-phrase-lookup`'s
  `HttpReasonPhraseLookup::getReasonPhrase()`) and `detail` the exception
  identity (short class name in minimal,
  `class: message` in full). It omits every member whose value is `null`
  or `false`, including `source` when it is `null`.
  `trace` is emitted as the frame list (`$this->trace` serializes to it);
  `truncated` is emitted only when `$this->trace->truncated` is
  `true`, so `Problem` holds no duplicated truncation flag.
- `Frame::jsonSerialize()` omits null members, including a null `args`.
  `args` is a list of type names (see "Argument types") and is emitted
  only when PHP reported arguments for the frame, so `args: []` is a
  reported empty list and an absent `args` member means PHP did not
  report arguments (as with `zend.exception_ignore_args=On`). `source`
  is omitted only when `null`, not when it is an empty or
  whitespace-only string.
- `Trace::jsonSerialize()` returns the frame list, so a `Trace` is
  exactly the value of the document's `trace` member.

`Problem`, `Trace`, and `Frame` never hand out structural arrays.

### Mode detection

`Mode::fromEnv()` reads `APP_ENV` first, then `APP_DEBUG`, via
`getenv()`. It takes no parameters; tests control it with PHPUnit's
`#[WithEnvironmentVariable('APP_ENV', 'dev')]`, which is repeatable and
accepts `null` to assert the unset case.

| Signal | Value | Result |
|--------|-------|--------|
| `APP_ENV` | `dev`, `development`, `local`, matched exactly | Full |
| `APP_ENV` | anything else, including empty | fall through to `APP_DEBUG` |
| `APP_DEBUG` | `1`, `true`, `on`, `yes` (case-insensitive) | Full |
| `APP_DEBUG` | anything else, including empty | Minimal |
| neither | — | Minimal |

Unrecognized values fail safe to Minimal. `test` is *not* treated as
full: a test-suite environment should not silently change the
response contract, and tests construct `Mode` explicitly.

`APP_ENV` is compared literally, so `DEV` and `Local` are unrecognized
and fall through: environment values are lower-case by convention, and a
mis-cased value silently selecting full mode is the failure this
guards against. `APP_DEBUG` is read with `FILTER_VALIDATE_BOOLEAN`,
which *is* case-insensitive (`TRUE`, `On`, `YES` all mean full),
so the two signals differ in case handling by construction.

## Document shapes

Minimal, HTTP 500:

```json
{"type":"about:blank","title":"Internal Server Error","status":500,"code":0,"detail":"RuntimeException"}
```

Full, HTTP 500:

```json
{
  "type": "about:blank",
  "title": "Internal Server Error",
  "status": 500,
  "code": 0,
  "detail": "RuntimeException: Something broke",
  "file": "src/Service/Thing.php",
  "line": 42,
  "source": "            throw new RuntimeException('Something broke');",
  "trace": [
    {
      "file": "src/Http/Controller.php",
      "line": 17,
      "function": "index",
      "class": "App\\Http\\Controller",
      "type": "->",
      "args": [],
      "source": "        $thing->run();"
    },
    {
      "file": "vendor/framework/router.php",
      "line": 88,
      "function": "dispatch",
      "class": "Framework\\Router",
      "type": "->",
      "args": [],
      "source": "        return $route->run($request);"
    }
  ]
}
```

`title` is the recommended HTTP status phrase for the document's
status — the phrase from `codeinc/http-reason-phrase-lookup`'s
`HttpReasonPhraseLookup::getReasonPhrase()` —
as RFC 9457 §4.2.1 requires when `type` is `about:blank`; a status with
no phrase there (for example 599) falls back to the status code as a
string. The exception identity is in `detail`: the
short (unqualified) class name in minimal mode, `class: message` in
full mode. `file`, `line`, and trace are the full-only members.

`instance` is omitted: there is no request-id infrastructure to point at,
and inventing a URI would be worse than omitting it.

## Trace pipeline

```
Throwable::getTrace() ──▶ TraceFactory ──▶ Document\Trace
                            ├─▶ PathRelativizer   relativize
                            ├─▶ SourceContext      source lines (every frame)
                            └─▶ ArgumentTyper      argument types
```

`TraceFactory` takes the trace array as an explicit input, not a
`Throwable`. Two reasons: frame arguments cannot be exercised through a
real `Throwable` without depending on `zend.exception_ignore_args`, and
the origin `file`/`line` come from `Throwable::getFile()`/`getLine()`
anyway, so the trace array is the only thing a `Throwable` contributes.
`ExceptionHandler` calls `$exception->getTrace()` and passes it in.

Collaborator signatures (all `@internal`):

```php
final class TraceFactory
{
    public function __construct(
        private PathRelativizer $relativizer,
        private SourceContext $source,
        private ArgumentTyper $types,
        private int $traceLimit,
    ) {}

    /**
     * @param list<array<string, mixed>> $trace
     */
    public function frames(array $trace): Trace;
}

final class SourceContext
{
    public function line(string $absolutePath, int $line): ?string;
}

final class ArgumentTyper
{
    public function type(mixed $value): string;
}
```

`ExceptionHandler` builds the origin source with
`line($originFile, $originLine)` and `TraceFactory` does the same for
every frame.

Snippets are always **read** from the absolute path and **reported** with
the relativized path. `SourceContext` never sees a relative path, and
`PathRelativizer::relativize()` never touches the filesystem.

Per-frame members: `file`, `line`, `function`, `class`, `type`, `args`,
`source`.

- `file`/`line`/`source` are omitted when the frame has no file
  (internal functions, `call_user_func` frames).
- `class` and `type` are omitted for plain function calls.
- PHP embeds source paths in closure function names (`{closure:/absolute/file.php:line}`) and anonymous class names (after a NUL byte, before the `:line$ordinal` suffix). Relativize those embedded paths when they are under the application directory, preserving the surrounding PHP-generated name; paths outside it keep `PathRelativizer`'s absolute-path behavior.
- `type` is `->` for instance method calls and `::` for static calls,
  matching the keys PHP itself provides in a trace entry.
- `args` is present only when PHP reported arguments for the frame, and is a list of type names (see
  "Argument types"). PHP omits the `args` key entirely when
  `zend.exception_ignore_args=On`; `TraceFactory` normalizes that (and a
  malformed key) to `null`, so the member is omitted. A frame PHP
  reports with an empty argument list still carries `args: []`.
- `source` is omitted when the frame has no usable file/line, the file
  cannot be read, or the reported line is outside the file.

PHP already lists the frame nearest the throw first, so `trace[0]` is
that frame. `traceLimit` keeps the innermost N frames; dropping any
frame sets `Trace::$truncated`, which `Problem` reports as
`truncated: true`.

### Source lines

- The source line at the reported line, for the origin and every frame
  alike (decision 23).
- `source` is a single string: the physical line at the reported line
  number, trimmed of surrounding whitespace. Do not otherwise filter,
  dedent, number, or decorate it.
- A blank or whitespace-only line is `""` and is still valid, present
  source. Missing context is distinct from a blank line.
- A file that cannot be read (missing, unreadable, or not a regular
  file), or a reported line outside the file, yields `null`. Serializers
  omit `source` in that case.
- One string keeps the JSON free of escaped newlines and nested objects.

Implementation: read the file once per distinct path with
`file($path, FILE_IGNORE_NEW_LINES)`, index the reported line, and
`trim()` it. Caching per instance prevents re-reading a file that
appears in several frames.

### Argument types

A total function: it may not throw and may not invoke user code. It
returns one type name per argument, in order; the value itself never
appears. Contract:

| Input | Output |
|-------|--------|
| array, `array_is_list()` true | `"vec"` |
| array, otherwise | `"dict"` |
| `SensitiveParameterValue` | the type of `getValue()` |
| anything else | `get_debug_type()` |

Rules:

- `get_debug_type()` names scalars (`null`, `bool`, `int`, `float`,
  `string`), objects and enums by FQCN, closures as `Closure`, and
  resources as `resource (stream)` or `resource (closed)`.
- Arrays are split by key: a list is `vec` and anything else is `dict`.
- A `SensitiveParameterValue` is unwrapped with `getValue()` and its
  inner value typed; the value is never emitted. PHP redacts sensitive
  parameters in the trace by substituting this object, so unwrapping it
  reports the protected value's type without exposing the value.
  `SensitiveParameterValue` is `final`, so `getValue()` cannot be
  overridden and cannot run user code. Verified on PHP 8.5.11: the
  substituted object's type is `SensitiveParameterValue`, exposing
  `__construct`, `getValue`, `__debugInfo`.
- There is no depth, item, or length limit, because no value is emitted.

### Path relativization

```php
final class PathRelativizer
{
    public function __construct(?string $projectDir = null);
    public function relativize(string $absolutePath): string;
}
```

`$projectDir` defaults to
`Composer\InstalledVersions::getRootPackage()['install_path']`, else
`getcwd()`; if `Composer\InstalledVersions` does not exist (a
non-Composer autoloader) it falls back to `getcwd()`.

Normalization is mandatory: Composer returns unnormalized paths.
Verified on this machine, `getRootPackage()['install_path']` is
`/Users/…/errata/vendor/composer/../../`. `realpath()` is applied first;
if it returns `false` (path does not exist) a lexical normalization
collapses `.` and `..` segments.

`relativize()` strips the project-directory prefix and converts
`DIRECTORY_SEPARATOR` to `/`, yielding `src/Foo.php`. Paths outside the
project directory are returned absolute: a
`../../../../usr/lib/php/…` chain is noise, not information.

## Status resolution

`ExceptionHandler` resolves the status, so `Problem::$status` is the
single source of truth and the middleware reads it back rather than
recomputing:

1. `$exception instanceof StatusCodeInterface` and
   `getStatusCode()` in `400..599` → that value.
2. Anything else → `500`.

Out-of-range values from the interface (including a buggy `200`) are
clamped to `500` rather than trusted, because a `2xx` problem document
would be a lie.

## Middleware behaviour

```
process(request, handler)
├─ try    handler->handle(request)                    → return response unchanged
└─ catch (Throwable $e)
   ├─ build Problem via ExceptionHandler (guarded)
   ├─ log via PSR-3 (guarded)
   ├─ encode JSON (guarded)
   └─ return responseFactory->createResponse(problem.status)
        ->withHeader('Content-Type', 'application/problem+json')
        ->withBody(body written via $response->getBody()->write($json))
```

Invariants, in priority order:

1. **The middleware never throws.** If `ExceptionHandler::handle()` (or
   anything else in the error path) throws, the middleware builds a
   minimal `Problem` using only `get_class()` and
   `getCode()`, and reports the secondary failure with `error_log()`.
2. **The response body is always JSON.** If `json_encode()` throws a
   `JsonException` (reachable only through pathological recursion), the
   body falls back to a constant minimal JSON document
   (`{"type":"about:blank","title":"Internal Server Error","status":500,"code":0}`).
   It stays a constant, so it carries no `detail`; RFC 9457 makes that
   member optional, and inventing a second encoding path inside the
   failure handler would be worse than omitting it.
3. **Logging never breaks the response.** A throwing logger is caught
   and reported with `error_log()`; processing continues.
4. **`ResponseFactoryInterface` alone suffices.** The body is written
   into the response returned by the factory, so no
   `StreamFactoryInterface` dependency is introduced.

Log record:

```php
$logger->log($logLevel, $exception->getMessage(), [
    'exception' => $exception,
    'method' => $request->getMethod(),
    'path' => $request->getUri()->getPath(),
    'status' => $problem->status,
]);
```

`exception` is the PSR-3 conventional key and carries the full
throwable, so handlers and processors (Monolog, Sentry) get the complete
trace regardless of mode. Method, path, and status correlate the log
line to a request. Headers, query strings, and bodies are deliberately
not logged.

Response headers: `Content-Type: application/problem+json` only. No
`Cache-Control` is added.

Encoding flags:
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR`.
Invalid UTF-8 in source lines is substituted rather than failing, and
`JSON_THROW_ON_ERROR` makes the failure path explicit instead of
returning `false`.

## Testing strategy

- PHPUnit 13 as configured in `phpunit.xml`: `requireCoverageMetadata`
  means every test case carries `#[CoversClass(...)]`; `composer run
  test` enforces 100% coverage, so no production line may be unreachable
  in tests.
- Unit tests per collaborator:
  - `Mode`: every signal/value combination from the detection
    table, including unrecognized values and `test`. Each row is its own
    test method carrying `#[WithEnvironmentVariable(...)]` — the
    attribute is fixed per method, so a data provider cannot drive it,
    and `value: null` covers the unset case.
  - `SourceContext`: fixtures on disk covering the first and last lines,
    blank and whitespace-only lines, indentation and trailing whitespace
    trimmed, LF/CRLF line separators, missing or unreadable files,
    non-file paths, and reported lines before the start or beyond EOF.
    Assert the exact source string, and `null` only for unavailable
    context. Also verify the existing per-instance file cache.
  - `ArgumentTyper`: scalars, `vec`/`dict` arrays including
    `array_is_list()` divergence (`[0 => 'a', 2 => 'b']`), objects,
    closures, enums, open and closed resources, and
    `SensitiveParameterValue` unwrapping to a scalar and to an array.
  - `PathRelativizer`: a path inside the project directory, a path
    outside it, a sibling directory whose name merely starts with the
    project directory name, and an unnormalized Composer path. The
    Composer default is exercised by constructing with no argument.
  - `TraceFactory`: synthetic trace arrays (no dependence on
    `zend.exception_ignore_args`), innermost-first ordering, the frame cap and
    `Trace::$truncated`, frames without a file, args omitted for a
    missing key and kept as an empty list for a reported empty list, and
    a frame whose file sits outside
    the project directory — still carrying its `source` line.
  - Document DTOs: each `jsonSerialize()` shape, member ordering and
    omission rules, null `source` omitted but blank source lines retained,
    and `truncated` present only when truncated.
  - `ExceptionHandler`: both modes, the status interface in and out of
    range, and chained exceptions.
- Middleware integration tests use `nyholm/psr7`'s `Psr17Factory` with a
  real `ServerRequest`, covering: pass-through, 4xx from the interface,
  a throwing logger, a throwing downstream handler, and a handler that
  throws a non-`Exception` `Error`.
- Verification is not tests alone: a throwaway script runs the
  middleware end to end in both modes against a real fixture exception
  and prints the bodies, confirming the shapes above.

### The `zend.exception_ignore_args` caveat

Frame arguments only exist when `zend.exception_ignore_args` is `Off`.
Verified against php-src: the core default is `Off`, `php.ini-development`
sets `Off`, and `php.ini-production` sets `On`. So arguments are present
in exactly the mode that emits traces, and absent in minimal mode where
traces are omitted anyway. The README must state this dependency, because
an app shipping `php.ini-production` while running in full mode
will see frames with no `args` member.

`TraceFactory` must therefore tolerate both shapes; there is no way for
the library to detect the setting's value that would be worth the
complexity.

## Repository integration

- `CHANGELOG.md` with an `Unreleased` section (AGENTS.md requires a
  changelog).
- `README.md`: installation, middleware wiring, the environment-variable
  table, the two body shapes, the `StatusCodeInterface` usage, and the
  `zend.exception_ignore_args` note.
- Conventional commits; semantic-version tags without a `v` prefix and
  GPG-signed.
- `composer run verify` (check, lint, analyze, test) is the gate; mago's
  `enforce-class-finality`, `require-api-or-internal`,
  `check-missing-type-hints`, `check-missing-override`, and
  `find-overly-wide-return-types` shape the code as written above.
