# Snafu — exception handler for JSON APIs

Date: 2026-09-30
Status: approved in brainstorming; pending review of this document

## Purpose

A small library that turns any uncaught `Throwable` in a JSON API into a
well-formed RFC 9457 problem document, logs it through PSR-3, and never
leaks production internals. Its unit of integration is a PSR-15
middleware; its unit of logic is a handler that maps `Throwable` to a
`Problem` value object.

Success criteria:

- A caught exception yields `application/problem+json` with an HTTP
  status and a body, in both modes, always.
- Production bodies contain the short exception class name and the
  exception code and nothing else that could leak internals.
- Development bodies contain the message, the origin `file`/`line`, and
  a frame list, each with a 5-line context window.
- No code path in the middleware can itself throw, including when
  logging fails or when JSON encoding fails.

## Requirements (given)

1. All output is JSON.
2. A PSR-15 middleware is included.
3. All exceptions are logged via PSR-3.
4. Production mode and development mode.
5. Production: short exception class name and code only.
6. Development: additionally message, file, line, trace.
7. Trace paths are relative to the application directory.
8. Context is always 5 lines (the error line, ±2), for the origin and
   for every frame alike.
9. Blank lines are stripped from context.

## Decisions

Decisions made during brainstorming, with the reasoning that matters for
later changes.

| # | Decision | Choice |
|---|----------|--------|
| 1 | Body format | RFC 9457 problem document, `application/problem+json` |
| 2 | `type` member | Always `about:blank` |
| 3 | Production body | Development-only members omitted entirely, never `null` |
| 4 | Class structure | Separate `ExceptionHandler` + `ExceptionMiddleware` |
| 5 | Logging | `LoggerInterface` optional (null disables), level configurable, default `LogLevel::ERROR` |
| 6 | Trace frame shape | Object with `{line, code}` snippet entries, snippet member named `source` |
| 7 | Vendor detection | None: no vendor flag, no vendor-directory config; every frame is treated identically |
| 8 | Frame order | Innermost-first (reversed `getTrace()` order) |
| 9 | Frame arguments | Included, truncated; `#[\SensitiveParameter]` respected |
| 10 | Path base | Composer-derived default, individually overridable |
| 11 | Trace length | Capped, default 30 frames, keeps the innermost frames |
| 12 | Environment source | `APP_ENV`, then `APP_DEBUG`, then Production |
| 13 | Non-500 status | Opt-in `StatusCodeInterface`; interface only, no shipped exception classes |
| 14 | `code` member | `(int) $exception->getCode()` |
| 15 | Handler return | `Problem` value object (`JsonSerializable`) |
| 16 | Argument truncation | Depth 5, 50 items, 500-char strings |
| 17 | Chained exceptions | Recursed in development, omitted in production |
| 18 | Logger failure | Caught, reported with `error_log()`, processing continues |
| 19 | Log context | `exception`, `method`, `path`, `status` |
| 20 | Objects in arguments | Class name plus public properties |
| 21 | Origin frame | Top-level `file`/`line`/`source` only; not duplicated into `trace` |
| 22 | Response headers | `Content-Type: application/problem+json` only |
| 23 | Context window | Always 5 lines (`line ± 2`), origin and frames alike; blank stripping can yield fewer |
| 24 | Document types | DTOs implementing `JsonSerializable`; bare arrays only for lists |
| 25 | Map payloads | String-keyed maps are carried by a DTO and serialized as a JSON object |
| 26 | API markers | Document DTOs are `@api`; collaborator classes are `@internal` |
| 27 | Handler seam | `ExceptionHandlerInterface`, implemented by `ExceptionHandler`, is what `ExceptionMiddleware` depends on |

Known, accepted consequences:

- Decision 14 flattens `PDOException`'s SQLSTATE string code to `0`.
  Preserving it would make the member a `int|string` union.
- Decision 8 reverses standard PHP order; clients must not assume
  `getTrace()` ordering.
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
  Environment.php                        enum Environment: string
  ExceptionHandler.php                   final class, implements ExceptionHandlerInterface
  ExceptionHandlerInterface.php          interface
  Http/StatusCodeInterface.php           interface
  Middleware/ExceptionMiddleware.php     final class, PSR-15
  Document/Problem.php                   final readonly, JsonSerializable
  Document/Trace.php                     final readonly, JsonSerializable
  Document/Frame.php                     final readonly, JsonSerializable
  Document/SourceLine.php                final readonly, JsonSerializable
  Document/SanitizedObject.php           final readonly, JsonSerializable
  Document/SanitizedMap.php              final readonly, JsonSerializable
  Path/PathRelativizer.php               final class
  Trace/TraceFactory.php                 final class
  Trace/SourceContext.php                final class
  Trace/ArgumentSanitizer.php            final class
tests/
  Fixtures/                              source files and traces used by tests
```

`Document/` holds every type that appears in the response body, because
that namespace *is* the JSON contract. `Trace/` holds the plumbing that
produces it.

Every class is `final` (mago `enforce-class-finality`). Document DTOs,
`Environment`, `ExceptionHandler`, `ExceptionHandlerInterface`,
`ExceptionMiddleware`, and `StatusCodeInterface` are marked `@api`;
`Path/`, `Trace/`, and the internal members of the rest are marked
`@internal` (mago `require-api-or-internal`). `Problem` is `@api`
because it is the return type of `ExceptionHandlerInterface::handle()`
and holds `Trace`, `Frame`, and `SourceLine`; marking it internal would
put an internal type in a public signature without making it
inaccessible.

Requires PHP 8.4 (`composer.json` is the single source of the version; CI
derives it from there), so no PHP 8.5 API is used. All nullable
parameters use explicit `?T` syntax, since PHP 8.4 deprecates implicit
nullable parameters.

## Public API

```php
/** @api */
enum Environment: string
{
    case Production = 'production';
    case Development = 'development';

    public static function fromEnv(): self;
}

namespace Snafu\Http;

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
        private Environment $environment,
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
    /**
     * @param list<SourceLine> $source
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public int $code,
        public ?string $detail = null,
        public ?string $file = null,
        public ?int $line = null,
        public array $source = [],
        public ?Trace $trace = null,
        public ?Problem $previous = null,
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class Trace implements JsonSerializable
{
    /**
     * @param list<Frame> $frames
     */
    public function __construct(
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
    /**
     * @param list<mixed>       $args
     * @param list<SourceLine>  $source
     */
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $function = null,
        public ?string $class = null,
        public ?string $type = null,
        public array $args = [],
        public array $source = [],
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class SourceLine implements JsonSerializable
{
    public function __construct(
        public int $line,
        public string $code,
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class SanitizedObject implements JsonSerializable
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}

/** @api */
final readonly class SanitizedMap implements JsonSerializable
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    #[Override]
    public function jsonSerialize(): array;
}
```

Serialization rules:

- `Problem::jsonSerialize()` emits members in this order:
  `type`, `title`, `status`, `code`, `detail`, `file`, `line`, `source`,
  `trace`, `traceTruncated`, `previous`. It omits every member whose
  value is `null` or `false`, and omits `source` when the list is empty.
  `trace` is emitted as the frame list (`$this->trace` serializes to it);
  `traceTruncated` is emitted only when `$this->trace->truncated` is
  `true`, so `Problem` holds no duplicated truncation flag.
- `Frame::jsonSerialize()` omits null members only. `args` is always
  present, including `args: []`; `source` is omitted when empty.
- `Trace::jsonSerialize()` returns the frame list, so a `Trace` is
  exactly the value of the document's `trace` member.
- `SanitizedObject` serializes as
  `{"@class": "<FQCN>", "props": {...}}`; `SanitizedMap` serializes as
  its entries, i.e. as a JSON object.

`SanitizedMap` exists only to keep string-keyed maps off the API surface:
a sanitized argument that is a PHP map is returned as a DTO, not a bare
array, while its JSON form stays an object. `Problem`, `Trace`, and
`Frame` likewise never hand out structural arrays.

### Environment detection

`Environment::fromEnv()` reads `APP_ENV` first, then `APP_DEBUG`, via
`getenv()`. It takes no parameters; tests control it with PHPUnit's
`#[WithEnvironmentVariable('APP_ENV', 'dev')]`, which is repeatable and
accepts `null` to assert the unset case.

| Signal | Value | Result |
|--------|-------|--------|
| `APP_ENV` | `dev`, `development`, `local`, matched exactly | Development |
| `APP_ENV` | anything else, including empty | fall through to `APP_DEBUG` |
| `APP_DEBUG` | `1`, `true`, `on`, `yes` (case-insensitive) | Development |
| `APP_DEBUG` | anything else, including empty | Production |
| neither | — | Production |

Unrecognized values fail safe to Production. `test` is *not* treated as
development: a test-suite environment should not silently change the
response contract, and tests construct `Environment` explicitly.

`APP_ENV` is compared literally, so `DEV` and `Local` are unrecognized
and fall through: environment values are lower-case by convention, and a
mis-cased value silently selecting development mode is the failure this
guards against. `APP_DEBUG` is read with `FILTER_VALIDATE_BOOLEAN`,
which *is* case-insensitive (`TRUE`, `On`, `YES` all mean development),
so the two signals differ in case handling by construction.

## Document shapes

Production, HTTP 500:

```json
{"type":"about:blank","title":"RuntimeException","status":500,"code":0}
```

Development, HTTP 500:

```json
{
  "type": "about:blank",
  "title": "RuntimeException",
  "status": 500,
  "code": 0,
  "detail": "Something broke",
  "file": "src/Service/Thing.php",
  "line": 42,
  "source": [{"line": 42, "code": "        throw new RuntimeException('Something broke');"}],
  "trace": [
    {
      "file": "src/Http/Controller.php",
      "line": 17,
      "function": "index",
      "class": "App\\Http\\Controller",
      "type": "->",
      "args": [],
      "source": [{"line": 17, "code": "        $thing->run();"}]
    },
    {
      "file": "vendor/framework/router.php",
      "line": 88,
      "function": "dispatch",
      "class": "Framework\\Router",
      "type": "->",
      "args": [],
      "source": [{"line": 88, "code": "        return $route->run($request);"}]
    }
  ]
}
```

`title` is the short (unqualified) exception class name in both modes,
per requirement 5. Requirement 6's `file`, `line`, and trace are the
development-only members; `detail` carries the message.

`instance` is omitted: there is no request-id infrastructure to point at,
and inventing a URI would be worse than omitting it.

## Trace pipeline

```
Throwable::getTrace() ──▶ TraceFactory ──▶ Document\Trace
                            ├─▶ PathRelativizer   relativize
                            ├─▶ SourceContext      windows (every frame)
                            └─▶ ArgumentSanitizer  args
                                  └─▶ SanitizedObject / SanitizedMap / lists
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
        private ArgumentSanitizer $arguments,
        private int $traceLimit,
    ) {}

    /**
     * @param list<array<string, mixed>> $trace
     */
    public function frames(array $trace): Trace;
}

final class SourceContext
{
    private const int CONTEXT_RADIUS = 2;

    /** @return list<SourceLine> */
    public function window(string $absolutePath, int $line): array;
}

final class ArgumentSanitizer
{
    public function sanitize(mixed $value): mixed;
}
```

`ExceptionHandler` builds the origin source with
`window($originFile, $originLine)` and `TraceFactory` does the same for
every frame. The radius is not configurable (decision 23), so
`SourceContext` owns it and no caller passes it.

Snippets are always **read** from the absolute path and **reported** with
the relativized path. `SourceContext` never sees a relative path, and
`PathRelativizer::relativize()` never touches the filesystem.

Per-frame members: `file`, `line`, `function`, `class`, `type`, `args`,
`source`.

- `file`/`line`/`source` are omitted when the frame has no file
  (internal functions, `call_user_func` frames).
- `class` and `type` are omitted for plain function calls.
- `type` is `->` for instance method calls and `::` for static calls,
  matching the keys PHP itself provides in a trace entry.
- `args` is always present (possibly an empty list). PHP omits the
  `args` key entirely when `zend.exception_ignore_args=On`, and
  `TraceFactory` normalizes that to an empty list.
- `source` is omitted only when the frame's file is unreadable or
  missing.

Frames are reversed, so `trace[0]` is the frame nearest the throw.
`traceLimit` keeps the innermost N frames; dropping any frame sets
`Trace::$truncated`, which `Problem` reports as `traceTruncated: true`.

### Source windows

- Always 5 lines: `line ± 2`, clamped to the file, for the origin and for
  every frame alike (decisions 23 and 27). Blank-line stripping can
  leave fewer than 5.
- Each entry is a `SourceLine`, serialized as
  `{"line": <real line number>, "code": <line text>}`. Real line numbers
  are preserved, so gaps left by removed blank lines are harmless — which
  is why a snippet is a line/code pair rather than a list of strings.
- Whitespace-only lines are dropped. Leading indentation and all other
  content are preserved verbatim; trailing whitespace is trimmed.
- A file that cannot be read (missing, unreadable, or a line beyond EOF)
  yields an empty list. Never an error, and never `null`: the "no
  context" case and the "nothing survived" case are the same to a client,
  so they are the same value.

Implementation: read the file once per distinct path with
`file($path, FILE_IGNORE_NEW_LINES)`, slice the window, then filter.
Caching per instance prevents re-reading a file that appears in several
frames.

### Argument sanitizer

A total function: it may not throw, may not invoke user code, and may
not loop forever. Contract:

| Input | Output |
|-------|--------|
| `null`, `bool`, `int`, `float` | verbatim |
| `string` | verbatim up to 500 chars, else first 500 chars + `...` |
| list array | `list<mixed>` of sanitized values, up to 50 entries |
| map array | `SanitizedMap`, up to 50 entries |
| `SensitiveParameterValue` | `"*redacted*"` |
| other object | `SanitizedObject` (class name + public properties) |
| `Closure` | `"Closure"` |
| `enum` | `"<FQCN>::<CASE>"` |
| resource | `"resource(<type>)"` (via `get_resource_type()`) |
| anything past depth 5 | `"*depth limit*"` |

Rules:

- Arrays are split by key: a list stays a list (`array_is_list()`), a map
  becomes a `SanitizedMap`. A list is never serialized as a JSON object
  and a map is never serialized as a JSON array.
- Cycles are detected with `SplObjectStorage`; a repeated object is
  emitted as `SanitizedObject` with an empty property map.
- Public properties come from `get_object_vars()` called in the
  sanitizer's own scope, which returns public properties only and does
  not trigger `__get`. Uninitialized typed properties are absent
  automatically. `__debugInfo`, `__toString`, and `JsonSerializable` are
  never invoked.
- `SensitiveParameterValue` is detected with `instanceof` before any
  other object branch, and `getValue()` is never called. PHP redacts
  sensitive parameters in the trace by substituting this object, so
  respecting it is a correctness requirement, not a nicety. Verified on
  PHP 8.5.11: the substituted object's type is `SensitiveParameterValue`,
  exposing `__construct`, `getValue`, `__debugInfo`.
- Truncation is marked in the payload, because a JSON array cannot carry
  a named marker: a truncated list ends with the string
  `"... (N more items)"`, and a truncated map gains the entry
  `"*truncated*": "N more items"`. Array keys are stringified the same
  way `json_encode()` would.

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
`/Users/…/snafu/vendor/composer/../../`. `realpath()` is applied first;
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
   minimal production-shaped `Problem` using only `get_class()` and
   `getCode()`, and reports the secondary failure with `error_log()`.
2. **The response body is always JSON.** If `json_encode()` throws a
   `JsonException` (reachable only through pathological recursion), the
   body falls back to a constant minimal JSON document.
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
Invalid UTF-8 in source windows is substituted rather than failing, and
`JSON_THROW_ON_ERROR` makes the failure path explicit instead of
returning `false`.

## Testing strategy

- PHPUnit 13 as configured in `phpunit.xml`: `requireCoverageMetadata`
  means every test case carries `#[CoversClass(...)]`; `composer run
  test` enforces 100% coverage, so no production line may be unreachable
  in tests.
- Unit tests per collaborator:
  - `Environment`: every signal/value combination from the detection
    table, including unrecognized values and `test`. Each row is its own
    test method carrying `#[WithEnvironmentVariable(...)]` — the
    attribute is fixed per method, so a data provider cannot drive it,
    and `value: null` covers the unset case.
  - `SourceContext`: fixtures on disk covering a window at line 1, a
    window at EOF, blank lines inside a window, indentation preserved, a
    missing file, and an unreadable or nonexistent path. Asserts
    `SourceLine` values, including that real line numbers survive blank
    removal.
  - `ArgumentSanitizer`: scalars, long strings, list and map arrays,
    `array_is_list()` divergence (`[0 => 'a', 2 => 'b']`), 50+ entries in
    both shapes, nesting past depth 5, cyclic object graphs,
    `SensitiveParameterValue`, closures, enums, resources, objects with
    public/private/protected properties, and objects with a throwing
    `__toString`.
  - `PathRelativizer`: a path inside the project directory, a path
    outside it, a sibling directory whose name merely starts with the
    project directory name, and an unnormalized Composer path. The
    Composer default is exercised by constructing with no argument.
  - `TraceFactory`: synthetic trace arrays (no dependence on
    `zend.exception_ignore_args`), reversed ordering, the frame cap and
    `Trace::$truncated`, frames without a file, args normalized from a
    missing key to an empty list, and a frame whose file sits outside
    the project directory — still carrying its `source` window.
  - Document DTOs: each `jsonSerialize()` shape, member ordering and
    omission rules, empty `source` omitted, `traceTruncated` present only
    when truncated, and `SanitizedMap`/`SanitizedObject` payload shapes.
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
in exactly the mode that emits traces, and absent in production where
traces are omitted anyway. The README must state this dependency, because
an app shipping `php.ini-production` while running in development mode
will see empty `args` lists.

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
