# Errata Exception Handler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP 8.4 library that turns any uncaught `Throwable` into an RFC 9457 `application/problem+json` document, logs it via PSR-3, and exposes it through a PSR-15 middleware — with full and minimal modes.

**Architecture:** Ten focused units. Value objects in `Errata\Document\` own the JSON contract; `Errata\Trace\` holds the plumbing that reads source lines, sanitizes arguments, and assembles frames; `Errata\Path\PathRelativizer` turns absolute paths into project-relative ones; `Errata\ExceptionHandler` maps a `Throwable` to a `Problem`; `Errata\Middleware\ExceptionMiddleware` catches, logs, encodes, and responds. Every unit is independently testable, which the 100% coverage gate requires.

**Tech Stack:** PHP 8.4, PSR-7 (`psr/http-factory`), PSR-15 (`psr/http-server-middleware`), PSR-3 (`psr/log`), `composer-runtime-api` (`Composer\InstalledVersions`), `codeinc/http-reason-phrase-lookup` (`CodeInc\HttpReasonPhraseLookup\HttpReasonPhraseLookup`), PHPUnit 13.3, Mago for lint/analyze/format, `nyholm/psr7` for PSR-7 test doubles.

**Spec:** `docs/superpowers/specs/2026-09-30-errata-exception-handler-design.md` — read it alongside this plan; it records why each decision was made.

> **Source-context amendments (2026-10-01, implemented):** The spec requires `source` as a single string: the
> source line at the reported line, trimmed of surrounding whitespace. This supersedes the `SourceLine`
> list, ±2 window, blank-line stripping, the `{start, end, code}` block, and related signatures/tests throughout
> this original implementation plan. These tasks describe the original design; the implementation now follows the
> amended spec.

## Global Constraints

Copied from the spec; every task implicitly includes them.

- PHP floor is 8.4 (`composer.json` `require.php` is the single source of truth and CI derives the CI version from it). Local dev runs 8.5.11 — never use an 8.5-only API (`array_first`, `|>`, `#[\NoDiscard]`).
- Every class is `final` (mago `enforce-class-finality`).
- Docblock `@api` on: `Mode`, `ExceptionHandler`, `ExceptionMiddleware`, `Http\StatusCodeInterface`, and all five `Document\` DTOs. Docblock `@internal` on every other class (mago `require-api-or-internal`).
- Every overriding method carries `#[Override]`.
- Full type hints on parameters, returns, and closures. Nullable parameters use explicit `?T` (PHP 8.4 deprecates implicit nullable).
- 100% line coverage is enforced by `composer run test`. No unreachable line may exist in `src/`, because no test can cover it.
- Test classes carry `#[CoversClass(...)]` (`phpunit.xml` sets `requireCoverageMetadata="true"`); test methods use the `test` prefix (verified: PHPUnit 13.3.6 `Util\Test::isTestMethod` still honours it).
- Output is JSON only, media type `application/problem+json`, `type` is always `about:blank`, `title` is the recommended HTTP status phrase from `codeinc/http-reason-phrase-lookup`'s `HttpReasonPhraseLookup::getReasonPhrase()` (RFC 9457 §4.2.1), and `detail` carries the exception identity.
- Source context is the source line at the reported line, trimmed of surrounding whitespace, for the origin and every frame.
- **There is no vendor detection.** No vendor flag, no vendor-directory config, no special case for third-party paths.
- Document types are `JsonSerializable` DTOs. Bare PHP arrays appear only as *lists*, plus the two places PHP forces them: `jsonSerialize(): array` and `Throwable::getTrace()`'s input.
- Argument truncation limits: depth 5, 50 items, 500-byte strings. Markers: `*depth limit*`, `*redacted*`, `*truncated*`, `... (N more items)`.
- Conventional commits. `CHANGELOG.md` is maintained. Release tags: semver, no `v` prefix, GPG-signed.
- Mago errors fail the gate; help/warning diagnostics do not (verified: `composer run lint` exits 0 with 6 diagnostics on a small probe file). Run `composer run fix` before each commit to let mago normalize fixable style.
- `error_log()` is the only channel for secondary failures (logger failures, handler failures, encoding failures). The response is never sacrificed to a broken sink.

## Review Focus

Failure modes the spec implies but no single task obviously owns. Each has a test added to the owning task.

1. **Invalid UTF-8 in a source file** — a PHP file containing raw non-UTF-8 bytes must still produce a decodable JSON body (the byte is substituted, not fatal). Owning task: 9.
2. **The handler and the logger both fail at once** — the middleware must still return a 500 JSON body from a code path that cannot itself fail. Owning task: 9.
3. **A long `previous` chain in full mode** — every link renders a nested document with its own window, with no crash and no arbitrary depth limit. Owning task: 8.
4. **Frames PHP reports without a file, or without an `args` key** — production `php.ini` sets `zend.exception_ignore_args=On`, which removes `args` entirely, so affected frames carry no `args` member; internal-function frames have no file. Neither may break assembly. Owning task: 7.
5. **A non-integer exception code** — `PDOException::getCode()` returns a string SQLSTATE (`'HY000'`), and `Exception::getCode()` is `final`, so this is the only realistic source. The cast must yield `0` without erroring. Owning task: 8.

---

### Task 1: Mode enum

**Files:**
- Create: `src/Mode.php`
- Test: `tests/ModeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Errata\Mode` — `enum Mode: string` with cases `Full = 'full'` and `Minimal = 'minimal'`, and `public static function fromEnv(): self`.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;
use PHPUnit\Framework\TestCase;
use Errata\Mode;

#[CoversClass(Mode::class)]
final class ModeTest extends TestCase
{
    #[WithEnvironmentVariable('APP_ENV', null)]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testNothingSetIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'dev')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvDevIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'LOCAL')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvIsCaseSensitive(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'test')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvTestIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', '1')]
    public function testAppDebugOneIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', 'TRUE')]
    public function testAppDebugIsCaseInsensitive(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'development')]
    #[WithEnvironmentVariable('APP_DEBUG', null)]
    public function testAppEnvDevelopmentIsFull(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', '')]
    #[WithEnvironmentVariable('APP_DEBUG', 'on')]
    public function testEmptyAppEnvFallsThroughToAppDebug(): void
    {
        $this->assertSame(Mode::Full, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', '0')]
    public function testFalsyAppDebugIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }

    #[WithEnvironmentVariable('APP_ENV', 'production')]
    #[WithEnvironmentVariable('APP_DEBUG', 'nonsense')]
    public function testUnrecognisedAppDebugIsMinimal(): void
    {
        $this->assertSame(Mode::Minimal, Mode::fromEnv());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/ModeTest.php`
Expected: FAIL — `Class "Errata\Mode" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php declare(strict_types=1);

namespace Errata;

/**
 * The document mode the handler renders.
 *
 * @api
 */
enum Mode: string
{
    case Full = 'full';
    case Minimal = 'minimal';

    /**
     * Detects the mode from the process environment.
     *
     * Reads `APP_ENV`, then `APP_DEBUG`. Unrecognised or absent values
     * fall back to Minimal, because a mode that leaks internals must
     * never be selected by accident.
     */
    public static function fromEnv(): self
    {
        $appEnv = getenv('APP_ENV');

        if ($appEnv === 'dev' || $appEnv === 'development' || $appEnv === 'local') {
            return self::Full;
        }

        return filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)
            ? self::Full
            : self::Minimal;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/ModeTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Mode.php tests/ModeTest.php
git commit -m "feat: detect the document mode from the environment"
```

---

### Task 2: SourceLine DTO and SourceContext

**Files:**
- Create: `src/Document/SourceLine.php`
- Create: `src/Trace/SourceContext.php`
- Create: `tests/Fixtures/source/window.php`
- Test: `tests/Document/SourceLineTest.php`
- Test: `tests/Trace/SourceContextTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Errata\Document\SourceLine` — `__construct(public int $line, public string $code)`, `jsonSerialize(): array` returning `['line' => int, 'code' => string]`.
  - `Errata\Trace\SourceContext` — `window(string $absolutePath, int $line): list<SourceLine>`, always 5 lines (`line ± 2`), blank lines removed, empty list when the file is unreadable or the line is outside the file.

- [ ] **Step 1: Create the fixture file**

Content must be exactly these 13 lines (the tests below assert against these line numbers):

```php
<?php declare(strict_types=1);

function errata_fixture_alpha(): void
{
    $alpha = 1;

    $beta = 2;
}

function errata_fixture_omega(): void
{
    $omega = 3;
}
```

Line 1 is `<?php declare(strict_types=1);`, line 2 is empty, line 3 is `function errata_fixture_alpha(): void`, line 4 is `{`, line 5 is `    $alpha = 1;`, line 6 is empty, line 7 is `    $beta = 2;`, line 8 is `}`, line 9 is empty, line 10 is `function errata_fixture_omega(): void`, line 11 is `{`, line 12 is `    $omega = 3;`, line 13 is `}`.

- [ ] **Step 2: Write the failing tests**

`tests/Document/SourceLineTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\SourceLine;

#[CoversClass(SourceLine::class)]
final class SourceLineTest extends TestCase
{
    public function testItSerializesLineAndCode(): void
    {
        $this->assertSame(
            ['line' => 42, 'code' => '    $value = 1;'],
            (new SourceLine(42, '    $value = 1;'))->jsonSerialize(),
        );
    }
}
```

`tests/Trace/SourceContextTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Trace;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\SourceLine;
use Errata\Trace\SourceContext;

#[CoversClass(SourceContext::class)]
final class SourceContextTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/source/window.php';

    private SourceContext $context;

    protected function setUp(): void
    {
        $this->context = new SourceContext();
    }

    public function testItReturnsTheFiveLineWindowAroundTheLine(): void
    {
        $window = $this->context->window(self::FIXTURE, 5);

        $this->assertSame(
            [
                ['line' => 3, 'code' => 'function errata_fixture_alpha(): void'],
                ['line' => 4, 'code' => '{'],
                ['line' => 5, 'code' => '    $alpha = 1;'],
                ['line' => 7, 'code' => '    $beta = 2;'],
            ],
            array_map(static fn (SourceLine $line): array => $line->jsonSerialize(), $window),
        );
    }

    public function testItClampsTheWindowAtTheStartOfTheFile(): void
    {
        $window = $this->context->window(self::FIXTURE, 1);

        $this->assertSame([1, 3], array_map(static fn (SourceLine $line): int => $line->line, $window));
    }

    public function testItClampsTheWindowAtTheEndOfTheFile(): void
    {
        $window = $this->context->window(self::FIXTURE, 13);

        $this->assertSame([11, 12, 13], array_map(static fn (SourceLine $line): int => $line->line, $window));
    }

    public function testItReturnsNothingForALinePastTheEndOfTheFile(): void
    {
        $this->assertSame([], $this->context->window(self::FIXTURE, 999));
    }

    public function testItReturnsNothingForALineBeforeTheStartOfTheFile(): void
    {
        $this->assertSame([], $this->context->window(self::FIXTURE, 0));
    }

    public function testItReturnsNothingForAMissingFile(): void
    {
        $this->assertSame([], $this->context->window('/nonexistent/errata/window.php', 1));
    }

    public function testItReturnsNothingForADirectory(): void
    {
        $this->assertSame([], $this->context->window(__DIR__ . '/../Fixtures/source', 1));
    }

    public function testItCachesFileContentsAcrossCalls(): void
    {
        $this->assertSame(
            $this->context->window(self::FIXTURE, 5),
            $this->context->window(self::FIXTURE, 5),
        );
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Document/SourceLineTest.php tests/Trace/SourceContextTest.php`
Expected: FAIL — `Class "Errata\Document\SourceLine" not found`.

- [ ] **Step 4: Write the implementation**

`src/Document/SourceLine.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * One line of source context.
 *
 * @api
 */
final readonly class SourceLine implements JsonSerializable
{
    public function __construct(
        public int $line,
        public string $code,
    ) {}

    /**
     * @return array{line: int, code: string}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['line' => $this->line, 'code' => $this->code];
    }
}
```

`src/Trace/SourceContext.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Trace;

use Errata\Document\SourceLine;

/**
 * Reads the fixed source window around a line of a file.
 *
 * @internal
 */
final class SourceContext
{
    /**
     * Lines kept either side of the reported line: 5 lines in total.
     */
    private const int CONTEXT_RADIUS = 2;

    /**
     * Contents of files already read, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private array $files = [];

    /**
     * Returns the 5 lines around `$line`, with blank lines removed.
     *
     * A file that cannot be read, or a line outside the file, yields an
     * empty list: "no context" and "nothing survived blank stripping"
     * are the same thing to a consumer.
     *
     * @return list<SourceLine>
     */
    public function window(string $absolutePath, int $line): array
    {
        $lines = $this->lines($absolutePath);

        if ($lines === [] || $line < 1 || $line > count($lines)) {
            return [];
        }

        $first = max(1, $line - self::CONTEXT_RADIUS);
        $last = min(count($lines), $line + self::CONTEXT_RADIUS);

        $window = [];

        for ($current = $first; $current <= $last; $current++) {
            $code = rtrim($lines[$current - 1]);

            if ($code === '') {
                continue;
            }

            $window[] = new SourceLine($current, $code);
        }

        return $window;
    }

    /**
     * @return list<string>
     */
    private function lines(string $absolutePath): array
    {
        if (array_key_exists($absolutePath, $this->files)) {
            return $this->files[$absolutePath];
        }

        $lines = [];

        if (is_file($absolutePath) && is_readable($absolutePath)) {
            $read = file($absolutePath, FILE_IGNORE_NEW_LINES);

            if ($read !== false) {
                $lines = $read;
            }
        }

        return $this->files[$absolutePath] = $lines;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Document/SourceLineTest.php tests/Trace/SourceContextTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 6: Commit**

```bash
composer run fix
git add src/Document/SourceLine.php src/Trace/SourceContext.php tests/Fixtures/source/window.php tests/Document/SourceLineTest.php tests/Trace/SourceContextTest.php
git commit -m "feat: read the five-line source window around a line"
```

---

### Task 3: Argument sanitizer

**Files:**
- Create: `src/Document/SanitizedObject.php`
- Create: `src/Document/SanitizedMap.php`
- Create: `src/Trace/ArgumentSanitizer.php`
- Test: `tests/Document/SanitizedObjectTest.php`
- Test: `tests/Document/SanitizedMapTest.php`
- Test: `tests/Trace/ArgumentSanitizerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Errata\Document\SanitizedObject` — `__construct(public string $class, public array $properties)`, serializes to `['@class' => string, 'props' => array]`.
  - `Errata\Document\SanitizedMap` — `__construct(public array $entries)`, serializes to its entries (a JSON object).
  - `Errata\Trace\ArgumentSanitizer` — `sanitize(mixed $value): mixed`, total (never throws), limits depth 5 / 50 items / 500 bytes.
  - `sanitize()` returns: scalars verbatim; strings truncated with `...`; lists as `list<mixed>`; maps as `SanitizedMap`; plain objects as `SanitizedObject` with at most 50 public properties and a `*truncated*` marker for the remainder; `Closure` as `'Closure'`; enums as `'FQCN::CASE'`; resources as `'resource(type)'`; `SensitiveParameterValue` as `'*redacted*'`; anything past depth 5 as `'*depth limit*'`; a closed resource as `'resource(closed)'`.

- [ ] **Step 1: Write the failing tests**

`tests/Document/SanitizedObjectTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\SanitizedObject;

#[CoversClass(SanitizedObject::class)]
final class SanitizedObjectTest extends TestCase
{
    public function testItSerializesClassAndProperties(): void
    {
        $this->assertSame(
            ['@class' => 'App\Thing', 'props' => ['id' => 1]],
            (new SanitizedObject('App\Thing', ['id' => 1]))->jsonSerialize(),
        );
    }
}
```

`tests/Document/SanitizedMapTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\SanitizedMap;

#[CoversClass(SanitizedMap::class)]
final class SanitizedMapTest extends TestCase
{
    public function testItSerializesItsEntries(): void
    {
        $this->assertSame(
            ['alpha' => 1, 'beta' => 'two'],
            (new SanitizedMap(['alpha' => 1, 'beta' => 'two']))->jsonSerialize(),
        );
    }
}
```

`tests/Trace/ArgumentSanitizerTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Trace;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameterValue;
use Errata\Document\SanitizedMap;
use Errata\Document\SanitizedObject;
use Errata\Trace\ArgumentSanitizer;
use stdClass;
use Stringable;

#[CoversClass(ArgumentSanitizer::class)]
final class ArgumentSanitizerTest extends TestCase
{
    private ArgumentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new ArgumentSanitizer();
    }

    public function testItPassesScalarsThroughUnchanged(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertTrue($this->sanitizer->sanitize(true));
        $this->assertSame(7, $this->sanitizer->sanitize(7));
        $this->assertSame(1.5, $this->sanitizer->sanitize(1.5));
        $this->assertSame('short', $this->sanitizer->sanitize('short'));
    }

    public function testItTruncatesLongStrings(): void
    {
        $sanitized = $this->sanitizer->sanitize(str_repeat('a', 501));

        $this->assertIsString($sanitized);
        $this->assertSame(503, strlen($sanitized));
        $this->assertStringEndsWith('...', $sanitized);
    }

    public function testItKeepsListsAsLists(): void
    {
        $this->assertSame([1, 'two'], $this->sanitizer->sanitize([1, 'two']));
    }

    public function testItTreatsNonSequentialKeysAsAMap(): void
    {
        $sanitized = $this->sanitizer->sanitize([0 => 'a', 2 => 'b']);

        $this->assertInstanceOf(SanitizedMap::class, $sanitized);
        $this->assertSame('{"0":"a","2":"b"}', json_encode($sanitized));
    }

    public function testItMarksATruncatedList(): void
    {
        $json = json_encode($this->sanitizer->sanitize(range(1, 60)));

        $this->assertIsString($json);
        $this->assertStringEndsWith(',"... (10 more items)"]', $json);
        $this->assertSame(51, substr_count($json, ',') + 1);
    }

    public function testItMarksATruncatedMap(): void
    {
        $entries = [];

        for ($i = 0; $i < 60; $i++) {
            $entries['key' . $i] = $i;
        }

        $sanitized = $this->sanitizer->sanitize($entries);

        $this->assertInstanceOf(SanitizedMap::class, $sanitized);
        $this->assertSame(51, count($sanitized->entries));
        $this->assertSame(['*truncated*' => '10 more items'], array_slice($sanitized->entries, -1, null, true));
    }

    public function testItStopsAtTheDepthLimit(): void
    {
        $nested = ['end'];

        for ($i = 0; $i < 10; $i++) {
            $nested = [$nested];
        }

        $json = json_encode($this->sanitizer->sanitize($nested));

        $this->assertIsString($json);
        $this->assertStringContainsString('*depth limit*', $json);
        $this->assertSame(5, substr_count($json, '['));
    }

    public function testItEmptiesARepeatedObjectInsteadOfRecursing(): void
    {
        $node = new stdClass();
        $node->self = $node;

        $this->assertSame(
            '{"@class":"stdClass","props":{"self":{"@class":"stdClass","props":[]}}}',
            json_encode($this->sanitizer->sanitize($node)),
        );
    }

    public function testItKeepsPublicPropertiesOnly(): void
    {
        $json = json_encode($this->sanitizer->sanitize(new ArgumentSanitizerFixture()));

        $this->assertIsString($json);
        $this->assertStringContainsString('"props":{"public":"yes"}', $json);
        $this->assertStringNotContainsString('protected', $json);
        $this->assertStringNotContainsString('private', $json);
    }

    public function testItNeverCallsToString(): void
    {
        $sanitized = $this->sanitizer->sanitize(new ArgumentSanitizerHostileFixture());

        $this->assertInstanceOf(SanitizedObject::class, $sanitized);
        $this->assertSame([], $sanitized->properties);
    }

    public function testItRedactsSensitiveParameters(): void
    {
        $this->assertSame('*redacted*', $this->sanitizer->sanitize(new SensitiveParameterValue('hunter2')));
    }

    public function testItNamesClosuresAndEnums(): void
    {
        $this->assertSame('Closure', $this->sanitizer->sanitize(static fn (): int => 1));
        $this->assertSame(
            'Errata\Tests\Trace\ArgumentSanitizerEnum::Second',
            $this->sanitizer->sanitize(ArgumentSanitizerEnum::Second),
        );
    }

    public function testItDescribesResources(): void
    {
        $handle = fopen('php://memory', 'r');

        $this->assertSame('resource(stream)', $this->sanitizer->sanitize($handle));

        fclose($handle);

        $this->assertSame('resource(closed)', $this->sanitizer->sanitize($handle));
    }

    public function testItSanitizesPropertiesRecursively(): void
    {
        $payload = new stdClass();
        $payload->token = new SensitiveParameterValue('hunter2');
        $payload->nested = ['a' => str_repeat('b', 501)];

        $json = json_encode($this->sanitizer->sanitize($payload));

        $this->assertIsString($json);
        $this->assertStringContainsString('"token":"*redacted*"', $json);
        $this->assertStringContainsString('"nested":{"a":"' . str_repeat('b', 500) . '..."}', $json);
    }
}

enum ArgumentSanitizerEnum
{
    case First;
    case Second;
}

final class ArgumentSanitizerFixture
{
    public string $public = 'yes';

    protected string $protected = 'no';

    private string $private = 'no';

    /**
     * Reads every property, so the fixture has no unused members.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return [$this->public, $this->protected, $this->private];
    }
}

final class ArgumentSanitizerHostileFixture implements Stringable
{
    #[Override]
    public function __toString(): string
    {
        throw new RuntimeException('__toString must not be called');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Document/SanitizedObjectTest.php tests/Document/SanitizedMapTest.php tests/Trace/ArgumentSanitizerTest.php`
Expected: FAIL — `Class "Errata\Document\SanitizedObject" not found`.

- [ ] **Step 3: Write the implementation**

`src/Document/SanitizedObject.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * A sanitized object argument: class name plus public properties.
 *
 * @api
 */
final readonly class SanitizedObject implements JsonSerializable
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['@class' => $this->class, 'props' => $this->properties];
    }
}
```

`src/Document/SanitizedMap.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * A sanitized string-keyed array argument.
 *
 * Exists so string-keyed maps never reach an API boundary as bare PHP
 * arrays, while still serializing as a JSON object.
 *
 * @api
 */
final readonly class SanitizedMap implements JsonSerializable
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->entries;
    }
}
```

`src/Trace/ArgumentSanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace Errata\Trace;

use Closure;
use SensitiveParameterValue;
use Errata\Document\SanitizedMap;
use Errata\Document\SanitizedObject;
use SplObjectStorage;
use UnitEnum;

use function array_is_list;
use function array_slice;
use function count;
use function get_object_vars;
use function get_resource_type;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_nan;
use function is_object;
use function is_resource;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

/**
 * Converts an arbitrary argument value into something JSON-encodable.
 *
 * This is a total function: it must not throw, must not invoke user code
 * (`__toString`, `__debugInfo`, `JsonSerializable`), and must not loop
 * forever. It runs while an exception is being reported, so failing here
 * would destroy the report. It must not produce a value `json_encode()`
 * refuses.
 *
 * @internal
 */
final class ArgumentSanitizer
{
    private const int MAX_DEPTH = 5;

    private const int MAX_ITEMS = 50;

    private const int MAX_STRING_LENGTH = 500;

    /**
     * Objects on the current recursion path, used to stop cycles.
     *
     * @var SplObjectStorage<object, null>
     */
    private SplObjectStorage $processing;

    public function __construct()
    {
        /** @var SplObjectStorage<object, null> $processing */
        $processing = new SplObjectStorage();

        $this->processing = $processing;
    }

    public function sanitize(mixed $value): mixed
    {
        return $this->sanitizeValue($value, 0);
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $depth >= self::MAX_DEPTH => '*depth limit*',
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => self::float($value),
            is_string($value) => $this->sanitizeString($value),
            is_array($value) => $this->sanitizeArray($value, $depth),
            $value instanceof SensitiveParameterValue => '*redacted*',
            $value instanceof Closure => 'Closure',
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            is_object($value) => $this->sanitizeObject($value, $depth),
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            default => 'resource(closed)',
        };
    }

    /**
     * `json_encode()` refuses INF and NAN, and a document that cannot be
     * encoded is a document that cannot be reported, so non-finite
     * floats become their names.
     */
    private static function float(float $value): float|string
    {
        if (is_finite($value)) {
            return $value;
        }

        if (is_nan($value)) {
            return 'NAN';
        }

        return $value > 0.0 ? 'INF' : '-INF';
    }

    private function sanitizeString(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING_LENGTH) {
            return $value;
        }

        return substr(string: $value, offset: 0, length: self::MAX_STRING_LENGTH) . '...';
    }

    /**
     * A list stays a list; anything else becomes a SanitizedMap.
     *
     * @param array<array-key, mixed> $value
     *
     * @return list<mixed>|SanitizedMap
     */
    private function sanitizeArray(array $value, int $depth): array|SanitizedMap
    {
        $total = count($value);
        $slice = array_slice(array: $value, offset: 0, length: self::MAX_ITEMS, preserve_keys: true);

        if (array_is_list($value)) {
            $items = [];

            /** @var mixed $item */
            foreach ($slice as $item) {
                $items[] = $this->sanitizeValue($item, $depth + 1);
            }

            if ($total > self::MAX_ITEMS) {
                $items[] = sprintf('... (%d more items)', $total - self::MAX_ITEMS);
            }

            return $items;
        }

        return new SanitizedMap($this->sanitizeEntries($slice, $depth, $total));
    }

    /**
     * Sanitizes a capped slice of entries, appending the truncation marker
     * when the source held more items than the cap.
     *
     * @param array<array-key, mixed> $slice
     *
     * @return array<string, mixed>
     */
    private function sanitizeEntries(array $slice, int $depth, int $total): array
    {
        $entries = [];

        /** @var mixed $item */
        foreach ($slice as $key => $item) {
            $entries[(string) $key] = $this->sanitizeValue($item, $depth + 1);
        }

        if ($total > self::MAX_ITEMS) {
            $entries['*truncated*'] = sprintf('%d more items', $total - self::MAX_ITEMS);
        }

        return $entries;
    }

    private function sanitizeObject(object $value, int $depth): SanitizedObject
    {
        if ($this->processing->offsetExists($value)) {
            return new SanitizedObject($value::class, []);
        }

        $this->processing->offsetSet($value, null);

        try {
            /** @var array<string, mixed> $all */
            $all = get_object_vars($value);
            $slice = array_slice(array: $all, offset: 0, length: self::MAX_ITEMS, preserve_keys: true);

            return new SanitizedObject($value::class, $this->sanitizeEntries($slice, $depth, count($all)));
        } finally {
            $this->processing->offsetUnset($value);
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Document/SanitizedObjectTest.php tests/Document/SanitizedMapTest.php tests/Trace/ArgumentSanitizerTest.php`
Expected: PASS, 16 tests.

If `testItStopsAtTheDepthLimit` fails on the `substr_count` assertion, print the encoded value and adjust the expected count — the invariant to preserve is that nesting is bounded, not the literal number.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Document/SanitizedObject.php src/Document/SanitizedMap.php src/Trace/ArgumentSanitizer.php tests/Document/SanitizedObjectTest.php tests/Document/SanitizedMapTest.php tests/Trace/ArgumentSanitizerTest.php
git commit -m "feat: sanitize trace arguments without invoking user code"
```

---

### Task 4: PathRelativizer

**Files:**
- Create: `src/Path/PathRelativizer.php`
- Test: `tests/Path/PathRelativizerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Errata\Path\PathRelativizer` — `__construct(?string $projectDir = null)` where `null` resolves to `Composer\InstalledVersions::getRootPackage()['install_path']` and then to `getcwd()`; `relativize(string $absolutePath): string` strips the project-directory prefix on a directory boundary and converts `\` to `/`, returning the absolute path otherwise.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Path;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Path\PathRelativizer;

#[CoversClass(PathRelativizer::class)]
final class PathRelativizerTest extends TestCase
{
    public function testItStripsTheProjectDirectoryPrefix(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/src/Foo.php'));
    }

    public function testItStripsThePrefixRegardlessOfTrailingSlash(): void
    {
        $relativizer = new PathRelativizer('/app/');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/src/Foo.php'));
    }

    public function testItDoesNotStripASiblingDirectoryWithASharedPrefix(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('/app2/src/Foo.php', $relativizer->relativize('/app2/src/Foo.php'));
    }

    public function testItLeavesPathsOutsideTheProjectDirectoryAlone(): void
    {
        $relativizer = new PathRelativizer('/app');

        $this->assertSame('/usr/lib/php/Foo.php', $relativizer->relativize('/usr/lib/php/Foo.php'));
    }

    public function testItNormalizesUnnormalizedPaths(): void
    {
        $relativizer = new PathRelativizer('/app/vendor/composer/../..');

        $this->assertSame('src/Foo.php', $relativizer->relativize('/app/vendor/composer/../../src/Foo.php'));
    }

    public function testItConvertsWindowsSeparators(): void
    {
        $relativizer = new PathRelativizer('C:/app');

        $this->assertSame('src/Foo.php', $relativizer->relativize('C:\app\src\Foo.php'));
    }

    public function testItFallsBackToTheComposerRootPackageDirectory(): void
    {
        $relativizer = new PathRelativizer();

        $this->assertSame(
            'tests/Fixtures/source/window.php',
            $relativizer->relativize(__DIR__ . '/../Fixtures/source/window.php'),
        );
    }

    public function testItFallsBackToTheWorkingDirectoryForAnUnknownDirectory(): void
    {
        $relativizer = new PathRelativizer('/definitely/not/here');

        $this->assertSame('/definitely/not/here/src/Foo.php', $relativizer->relativize('/definitely/not/here/src/Foo.php'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Path/PathRelativizerTest.php`
Expected: FAIL — `Class "Errata\Path\PathRelativizer" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php declare(strict_types=1);

namespace Errata\Path;

use Composer\InstalledVersions;

/**
 * Turns absolute paths into paths relative to the application directory.
 *
 * @internal
 */
final class PathRelativizer
{
    private const string SEPARATOR = '/';

    private readonly string $projectDir;

    /**
     * `$projectDir` defaults to the Composer root package directory, and
     * to the working directory when Composer's runtime API is absent.
     */
    public function __construct(?string $projectDir = null)
    {
        $directory = $projectDir ?? self::composerRoot() ?? self::workingDirectory();

        $this->projectDir = rtrim(self::resolve($directory), self::SEPARATOR);
    }

    /**
     * Paths under the project directory lose the prefix and keep `/`
     * separators. Paths outside it stay absolute: a `../../../usr/lib`
     * chain is noise, not information.
     */
    public function relativize(string $absolutePath): string
    {
        $path = self::collapse($absolutePath);
        $prefix = $this->projectDir . self::SEPARATOR;

        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        return substr($path, strlen($prefix));
    }

    /**
     * Composer hands back unnormalized paths such as
     * `<root>/vendor/composer/../../`, so realpath comes first.
     */
    private static function resolve(string $path): string
    {
        $real = realpath($path);

        return $real === false ? self::collapse($path) : $real;
    }

    /**
     * Pure string normalization, no filesystem access.
     */
    private static function collapse(string $path): string
    {
        $path = str_replace('\\', self::SEPARATOR, $path);
        $rooted = str_starts_with($path, self::SEPARATOR);
        $segments = [];

        foreach (explode(self::SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return ($rooted ? self::SEPARATOR : '') . implode(self::SEPARATOR, $segments);
    }

    private static function composerRoot(): ?string
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }

        $package = InstalledVersions::getRootPackage();

        if (!array_key_exists('install_path', $package)) {
            return null;
        }

        return $package['install_path'];
    }

    private static function workingDirectory(): string
    {
        $directory = getcwd();

        return $directory === false ? '' : $directory;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Path/PathRelativizerTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Path/PathRelativizer.php tests/Path/PathRelativizerTest.php
git commit -m "feat: relativize exception paths against the project directory"
```

---

### Task 5: Frame and Trace DTOs

**Files:**
- Create: `src/Document/Frame.php`
- Create: `src/Document/Trace.php`
- Test: `tests/Document/FrameTest.php`
- Test: `tests/Document/TraceTest.php`

**Interfaces:**
- Consumes: `Errata\Document\SourceLine` (Task 2).
- Produces:
  - `Errata\Document\Frame` — `__construct(?string $file = null, ?int $line = null, ?string $function = null, ?string $class = null, ?string $type = null, ?array $args = null, array $source = [])`. Serializes members in order `file, line, function, class, type, args, source`, omitting null members and an empty `source`; `args` is emitted only when PHP reported arguments for the frame, so `args: []` is a reported empty list and an absent member means PHP did not report arguments.
  - `Errata\Document\Trace` — `__construct(public array $frames, public bool $truncated)`, `jsonSerialize(): list<Frame>` returning the frames.

- [ ] **Step 1: Write the failing tests**

`tests/Document/FrameTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\Frame;
use Errata\Document\SourceLine;

#[CoversClass(Frame::class)]
final class FrameTest extends TestCase
{
    public function testItIsEmptyWhenNothingIsKnown(): void
    {
        $this->assertSame([], new Frame()->jsonSerialize());
    }

    public function testItOmitsNullMembersAndEmptySource(): void
    {
        $frame = new Frame(file: 'src/Foo.php', line: 12, function: 'run', class: 'App\Foo', type: '->', args: [1], source: []);

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'function' => 'run',
                'class' => 'App\Foo',
                'type' => '->',
                'args' => [1],
            ],
            $frame->jsonSerialize(),
        );
    }

    public function testItIncludesSourceWhenPresent(): void
    {
        $frame = new Frame(file: 'src/Foo.php', line: 12, source: [new SourceLine(12, '    $x = 1;')]);

        $this->assertSame(
            [
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => [['line' => 12, 'code' => '    $x = 1;']],
            ],
            self::json($frame),
        );
    }

    public function testItEmitsAnEmptyArgumentListThatPhpReported(): void
    {
        $this->assertSame(['args' => []], new Frame(args: [])->jsonSerialize());
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Frame $frame): array
    {
        $decoded = json_decode(json_encode($frame, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new LogicException('The frame did not serialize to a JSON object.');
        }

        return $decoded;
    }
}
```

`tests/Document/TraceTest.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\Frame;
use Errata\Document\Trace;

#[CoversClass(Trace::class)]
final class TraceTest extends TestCase
{
    public function testItSerializesAsTheFrameList(): void
    {
        $trace = new Trace([new Frame(function: 'run')], true);

        $this->assertSame([['function' => 'run']], self::json($trace));
        $this->assertTrue($trace->truncated);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Trace $trace): array
    {
        $decoded = json_decode(json_encode($trace, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new LogicException('The trace did not serialize to a JSON array.');
        }

        return $decoded;
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Document/FrameTest.php tests/Document/TraceTest.php`
Expected: FAIL — `Class "Errata\Document\Frame" not found`.

- [ ] **Step 3: Write the implementation**

`src/Document/Frame.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * One frame of a trace.
 *
 * @api
 */
final readonly class Frame implements JsonSerializable
{
    /**
     * @param list<mixed>|null $args
     * @param list<SourceLine> $source
     */
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $function = null,
        public ?string $class = null,
        public ?string $type = null,
        public ?array $args = null,
        public array $source = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $frame = [];

        if ($this->file !== null) {
            $frame['file'] = $this->file;
        }

        if ($this->line !== null) {
            $frame['line'] = $this->line;
        }

        if ($this->function !== null) {
            $frame['function'] = $this->function;
        }

        if ($this->class !== null) {
            $frame['class'] = $this->class;
        }

        if ($this->type !== null) {
            $frame['type'] = $this->type;
        }

        if ($this->args !== null) {
            $frame['args'] = $this->args;
        }

        if ($this->source !== []) {
            $frame['source'] = $this->source;
        }

        return $frame;
    }
}
```

`src/Document/Trace.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use JsonSerializable;
use Override;

/**
 * The trace section of a problem document.
 *
 * Serializes to the frame list, so a `Trace` is exactly the value of the
 * document's `trace` member.
 *
 * @api
 */
final readonly class Trace implements JsonSerializable
{
    /**
     * @param list<Frame> $frames
     */
    public function __construct(
        public array $frames,
        public bool $truncated,
    ) {}

    /**
     * @return list<Frame>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->frames;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Document/FrameTest.php tests/Document/TraceTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Document/Frame.php src/Document/Trace.php tests/Document/FrameTest.php tests/Document/TraceTest.php
git commit -m "feat: add frame and trace document types"
```

---

### Task 6: Problem DTO

**Files:**
- Create: `src/Document/Problem.php`
- Test: `tests/Document/ProblemTest.php`

**Interfaces:**
- Consumes: `Errata\Document\SourceLine` (Task 2), `Errata\Document\Trace` (Task 5).
- Produces: `Errata\Document\Problem` —
  - `__construct(string $type, string $title, int $status, int $code, ?string $detail = null, ?string $file = null, ?int $line = null, array $source = [], ?Trace $trace = null, ?Problem $previous = null)`
  - `public static function minimal(Throwable $exception, int $status): self` — the status phrase, the short class name as `detail`, and the code.
  - `public static function development(Throwable $exception, int $status, string $file, int $line, array $source, Trace $trace, ?Problem $previous): self` — `minimal()` plus `detail` as `class: message`, origin, source window, trace, and cause.
  - `jsonSerialize(): array` emitting `type, title, status, code, detail, file, line, source, trace, truncated, previous`, omitting null/false members and empty `source`.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Document;

use Exception;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Errata\Document\Problem;
use Errata\Document\SourceLine;
use Errata\Document\Trace;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(Problem::class)]
final class ProblemTest extends TestCase
{
    public function testMinimalCarriesTheStatusPhraseTheClassAndTheCode(): void
    {
        $problem = Problem::minimal(new RuntimeException('secret detail', 7), 500);

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'code' => 7,
                'detail' => 'RuntimeException',
            ],
            $problem->jsonSerialize(),
        );
    }

    public function testTheShortNameOfAGlobalClassIsItsName(): void
    {
        $this->assertSame('Exception', Problem::minimal(new Exception('x'), 500)->detail);
    }

    public function testMinimalNeverLeaksTheOriginPathOfAnAnonymousClass(): void
    {
        $problem = Problem::minimal(new class extends RuntimeException {}, 500);
        $json = json_encode($problem, JSON_THROW_ON_ERROR);

        $this->assertSame('RuntimeException@anonymous', $problem->detail);
        $this->assertStringNotContainsString('\u0000', $json);
        $this->assertStringNotContainsString(__FILE__, $json);
    }

    public function testAMappedStatusCarriesTheRecommendedPhrase(): void
    {
        $this->assertSame('Not Found', Problem::minimal(new RuntimeException('x'), 404)->title);
    }

    public function testAnUnmappedStatusFallsBackToTheCode(): void
    {
        $this->assertSame('599', Problem::minimal(new RuntimeException('x'), 599)->title);
    }

    public function testANonIntegerCodeIsCast(): void
    {
        $code = $this->sqlStateCode();

        $this->assertIsString($code);
        $this->assertSame(0, Problem::minimal(new PDOException('x'), 500)->code);
        $this->assertSame(0, (int) $code);
    }

    public function testDevelopmentCarriesEveryDevelopmentMember(): void
    {
        $previous = Problem::minimal(new RuntimeException('cause'), 500);
        $problem = Problem::development(
            exception: new RuntimeException('boom', 3),
            status: 422,
            file: 'src/Foo.php',
            line: 12,
            source: [new SourceLine(12, '    throw new RuntimeException();')],
            trace: new Trace([], false),
            previous: $previous,
        );

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'code' => 3,
                'detail' => 'RuntimeException: boom',
                'file' => 'src/Foo.php',
                'line' => 12,
                'source' => [['line' => 12, 'code' => '    throw new RuntimeException();']],
                'trace' => [],
                'previous' => [
                    'type' => 'about:blank',
                    'title' => 'Internal Server Error',
                    'status' => 500,
                    'code' => 0,
                    'detail' => 'RuntimeException',
                ],
            ],
            self::json($problem),
        );
    }

    public function testDevelopmentOmitsAnEmptySourceAndANullPrevious(): void
    {
        $problem = Problem::development(
            exception: new RuntimeException('boom'),
            status: 500,
            file: 'src/Foo.php',
            line: 1,
            source: [],
            trace: new Trace([], false),
            previous: null,
        );

        $serialized = $problem->jsonSerialize();

        $this->assertArrayNotHasKey('source', $serialized);
        $this->assertArrayNotHasKey('previous', $serialized);
        $this->assertArrayNotHasKey('truncated', $serialized);
    }

    public function testDevelopmentFlagsATruncatedTrace(): void
    {
        $problem = Problem::development(
            exception: new RuntimeException('boom'),
            status: 500,
            file: 'src/Foo.php',
            line: 1,
            source: [],
            trace: new Trace([], true),
            previous: null,
        );

        $this->assertTrue($problem->jsonSerialize()['truncated'] ?? false);
    }

    public function testItCanBeConstructedDirectly(): void
    {
        $problem = new Problem(
            type: 'about:blank',
            title: 'Service Unavailable',
            status: 503,
            code: 0,
            detail: 'RuntimeException',
            file: 'f',
            line: 2,
        );

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Service Unavailable',
                'status' => 503,
                'code' => 0,
                'detail' => 'RuntimeException',
                'file' => 'f',
                'line' => 2,
            ],
            $problem->jsonSerialize(),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Problem $problem): array
    {
        return self::decoded(json_decode(
            json: json_encode($problem, JSON_THROW_ON_ERROR),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (!is_array($value)) {
            throw new LogicException('The problem did not serialize to a JSON object.');
        }

        return $value;
    }

    /**
     * A real PDO failure, so the string `getCode()` is genuine rather
     * than simulated: `Exception::getCode()` is final and cannot be
     * overridden.
     */
    private function sqlStateCode(): string|int
    {
        $pdo = new PDO('sqlite::memory:');

        try {
            $pdo->query('select * from errata_missing_table');
        } catch (PDOException $exception) {
            return $exception->getCode();
        }

        return 0;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Document/ProblemTest.php`
Expected: FAIL — `Class "Errata\Document\Problem" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php declare(strict_types=1);

namespace Errata\Document;

use CodeInc\HttpReasonPhraseLookup\HttpReasonPhraseLookup;
use JsonSerializable;
use Override;
use Throwable;

use function strpos;
use function strrpos;
use function substr;

/**
 * An RFC 9457 problem document.
 *
 * @api
 */
final readonly class Problem implements JsonSerializable
{
    /**
     * RFC 9457's "no semantic identification beyond the status" value.
     */
    private const string TYPE = 'about:blank';

    // @mago-ignore lint:excessive-parameter-list
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public int $code,
        public ?string $detail = null,
        public ?string $file = null,
        public ?int $line = null,
        /** @var list<SourceLine> */
        public array $source = [],
        public ?Trace $trace = null,
        public ?Problem $previous = null,
    ) {}

    /**
     * The minimal document: the status phrase, the short class name as
     * `detail`, and the code, nothing else.
     */
    public static function minimal(Throwable $exception, int $status): self
    {
        return new self(
            type: self::TYPE,
            title: self::title($status),
            status: $status,
            code: (int) $exception->getCode(),
            detail: self::shortClass($exception),
        );
    }

    /**
     * The full document: everything `minimal()` carries, plus `detail`
     * as `class: message`, the origin, the source window, the trace,
     * and the cause.
     *
     * @param list<SourceLine> $source
     *
     * @mago-ignore lint:excessive-parameter-list
     */
    public static function development(
        Throwable $exception,
        int $status,
        string $file,
        int $line,
        array $source,
        Trace $trace,
        ?Problem $previous,
    ): self {
        return new self(
            type: self::TYPE,
            title: self::title($status),
            status: $status,
            code: (int) $exception->getCode(),
            detail: self::detail($exception),
            file: $file,
            line: $line,
            source: $source,
            trace: $trace,
            previous: $previous,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        $document = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code,
        ];

        if ($this->detail !== null) {
            $document['detail'] = $this->detail;
        }

        if ($this->file !== null) {
            $document['file'] = $this->file;
        }

        if ($this->line !== null) {
            $document['line'] = $this->line;
        }

        if ($this->source !== []) {
            $document['source'] = $this->source;
        }

        if ($this->trace !== null) {
            $document['trace'] = $this->trace;

            if ($this->trace->truncated) {
                $document['truncated'] = true;
            }
        }

        if ($this->previous !== null) {
            $document['previous'] = $this->previous;
        }

        return $document;
    }

    /**
     * The reason phrase for `$status`, from
     * `codeinc/http-reason-phrase-lookup`. A status with no registered
     * phrase falls back to the code itself, so the title is never empty.
     */
    private static function title(int $status): string
    {
        return HttpReasonPhraseLookup::getReasonPhrase($status) ?? (string) $status;
    }

    /**
     * The dev-mode exception identity: the short class name, then the
     * message, always both.
     */
    private static function detail(Throwable $exception): string
    {
        return self::shortClass($exception) . ': ' . $exception->getMessage();
    }

    /**
     * The unqualified class name, truncated at the NUL byte that an
     * anonymous class carries before its origin path.
     */
    private static function shortClass(Throwable $exception): string
    {
        $class = $exception::class;
        $nul = strpos(haystack: $class, needle: "\0");

        if ($nul !== false) {
            $class = substr(string: $class, offset: 0, length: $nul);
        }

        $position = strrpos(haystack: $class, needle: '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Document/ProblemTest.php`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Document/Problem.php tests/Document/ProblemTest.php
git commit -m "feat: add the problem document type with both modes"
```

---

### Task 7: TraceFactory

**Files:**
- Create: `src/Trace/TraceFactory.php`
- Test: `tests/Trace/TraceFactoryTest.php`

**Interfaces:**
- Consumes: `Errata\Path\PathRelativizer` (Task 4), `Errata\Trace\SourceContext` (Task 2), `Errata\Trace\ArgumentSanitizer` (Task 3), `Errata\Document\Frame` and `Errata\Document\Trace` (Task 5).
- Produces: `Errata\Trace\TraceFactory` — `__construct(PathRelativizer $relativizer, SourceContext $source, ArgumentSanitizer $arguments, int $traceLimit)`, `frames(array $trace): Trace` where the input is PHP's `Throwable::getTrace()` array, which already lists the frame nearest the throw first, so the first `$traceLimit` entries — the innermost frames — are kept. An entry without an `args` key (or with a non-array one) yields a frame with no `args` member; an empty `args` array is kept as `args: []`.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Trace;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Errata\Document\Trace;
use Errata\Path\PathRelativizer;
use Errata\Trace\ArgumentSanitizer;
use Errata\Trace\SourceContext;
use Errata\Trace\TraceFactory;
use stdClass;

#[CoversClass(TraceFactory::class)]
final class TraceFactoryTest extends TestCase
{
    private const string ROOT_FIXTURE = '/tests/Fixtures/source/window.php';

    private string $root;

    private TraceFactory $factory;

    #[Override]
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->factory = new TraceFactory(
            new PathRelativizer($this->root),
            new SourceContext(),
            new ArgumentSanitizer(),
            traceLimit: 30,
        );
    }

    public function testItKeepsTheInnermostFrameFirst(): void
    {
        $trace = $this->factory->frames([
            ['file' => $this->root . self::ROOT_FIXTURE, 'line' => 5, 'function' => 'inner'],
            ['file' => $this->root . '/src/Outer.php', 'line' => 3, 'function' => 'outer'],
        ]);

        $this->assertFalse($trace->truncated);
        $this->assertSame(
            [
                [
                    'file' => 'tests/Fixtures/source/window.php',
                    'line' => 5,
                    'function' => 'inner',
                    'source' => [
                        ['line' => 3, 'code' => 'function errata_fixture_alpha(): void'],
                        ['line' => 4, 'code' => '{'],
                        ['line' => 5, 'code' => '    $alpha = 1;'],
                        ['line' => 7, 'code' => '    $beta = 2;'],
                    ],
                ],
                [
                    'file' => 'src/Outer.php',
                    'line' => 3,
                    'function' => 'outer',
                ],
            ],
            $this->serialize($trace),
        );
    }

    public function testItCarriesClassTypeAndArguments(): void
    {
        $trace = $this->factory->frames([
            [
                'file' => null,
                'line' => null,
                'function' => 'run',
                'class' => 'App\Thing',
                'type' => '->',
                'args' => ['plain', new stdClass()],
            ],
        ]);

        $this->assertSame(
            [
                [
                    'function' => 'run',
                    'class' => 'App\Thing',
                    'type' => '->',
                    'args' => ['plain', ['@class' => 'stdClass', 'props' => []]],
                ],
            ],
            $this->serialize($trace),
        );
    }

    public function testItKeepsFramesWithoutAFile(): void
    {
        $trace = $this->factory->frames([['function' => 'strlen', 'args' => []]]);

        $this->assertSame([['function' => 'strlen', 'args' => []]], $this->serialize($trace));
    }

    public function testItOmitsArgsWhenTheTraceHasNoArgsKey(): void
    {
        $trace = $this->factory->frames([['function' => 'strlen']]);

        $this->assertSame([['function' => 'strlen']], $this->serialize($trace));
    }

    public function testItIgnoresEntriesOfTheWrongType(): void
    {
        $trace = $this->factory->frames([[
            'file' => 42,
            'line' => 'twelve',
            'function' => ['nope'],
            'args' => 'not-an-array',
        ]]);

        $this->assertSame([[]], $this->serialize($trace));
    }

    public function testItKeepsTheInnermostFramesWhenTheLimitIsReached(): void
    {
        $trace = $this->factory->frames($this->manyFrames(40));
        /** @var list<array<string, mixed>> $frames */
        $frames = $this->serialize($trace);
        $functions = array_column($frames, 'function');

        $this->assertTrue($trace->truncated);
        $this->assertCount(30, $trace->frames);
        $this->assertSame(['frame-0'], array_slice($functions, 0, 1));
        $this->assertSame(['frame-29'], array_slice($functions, -1));
    }

    public function testItDoesNotFlagATraceAtExactlyTheLimit(): void
    {
        $trace = $this->factory->frames($this->manyFrames(30));

        $this->assertFalse($trace->truncated);
        $this->assertCount(30, $trace->frames);
    }

    public function testItSkipsTheSourceWindowWhenTheLineIsOutOfRange(): void
    {
        $trace = $this->factory->frames([[
            'file' => $this->root . self::ROOT_FIXTURE,
            'line' => 900,
            'function' => 'inner',
        ]]);

        $this->assertSame(
            [['file' => 'tests/Fixtures/source/window.php', 'line' => 900, 'function' => 'inner']],
            $this->serialize($trace),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function serialize(Trace $trace): array
    {
        $decoded = json_decode(json_encode($trace, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new LogicException('The trace did not serialize to a JSON array.');
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manyFrames(int $count): array
    {
        $trace = [];

        for ($i = 0; $i < $count; $i++) {
            $trace[] = ['function' => 'frame-' . $i];
        }

        return $trace;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Trace/TraceFactoryTest.php`
Expected: FAIL — `Class "Errata\Trace\TraceFactory" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php declare(strict_types=1);

namespace Errata\Trace;

use Errata\Document\Frame;
use Errata\Document\Trace;
use Errata\Path\PathRelativizer;

/**
 * Assembles the trace section from PHP's raw trace array.
 *
 * @internal
 */
final class TraceFactory
{
    public function __construct(
        private readonly PathRelativizer $relativizer,
        private readonly SourceContext $source,
        private readonly ArgumentSanitizer $arguments,
        private readonly int $traceLimit,
    ) {}

    /**
     * `Throwable::getTrace()` already lists the frame nearest the throw
     * first, so the first `$traceLimit` entries — the innermost frames —
     * are kept when the list is longer than that.
     *
     * @param list<array<string, mixed>> $trace
     */
    public function frames(array $trace): Trace
    {
        $truncated = count($trace) > $this->traceLimit;

        $frames = [];

        foreach (array_slice($trace, 0, $this->traceLimit) as $entry) {
            $frames[] = $this->frame($entry);
        }

        return new Trace($frames, $truncated);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function frame(array $entry): Frame
    {
        $file = $this->string($entry, 'file');
        $line = $this->int($entry, 'line');

        return new Frame(
            file: $file === null ? null : $this->relativizer->relativize($file),
            line: $line,
            function: $this->string($entry, 'function'),
            class: $this->string($entry, 'class'),
            type: $this->string($entry, 'type'),
            args: $this->args($entry),
            source: $file === null || $line === null ? [] : $this->source->window($file, $line),
        );
    }

    /**
     * PHP omits the `args` key entirely when
     * `zend.exception_ignore_args=On`, so an absent or malformed key
     * normalizes to `null` and `Frame` omits the member; a frame PHP
     * reports with an empty argument list still carries `args: []`.
     *
     * @param array<string, mixed> $entry
     *
     * @return list<mixed>|null
     */
    private function args(array $entry): ?array
    {
        if (!array_key_exists('args', $entry) || !is_array($entry['args'])) {
            return null;
        }

        $args = [];

        foreach ($entry['args'] as $argument) {
            $args[] = $this->arguments->sanitize($argument);
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function string(array $entry, string $key): ?string
    {
        if (!array_key_exists($key, $entry) || !is_string($entry[$key])) {
            return null;
        }

        return $entry[$key];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function int(array $entry, string $key): ?int
    {
        if (!array_key_exists($key, $entry) || !is_int($entry[$key])) {
            return null;
        }

        return $entry[$key];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Trace/TraceFactoryTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Trace/TraceFactory.php tests/Trace/TraceFactoryTest.php
git commit -m "feat: assemble trace frames with source windows and arguments"
```

---

### Task 8: StatusCodeInterface and ExceptionHandler

**Files:**
- Create: `src/Http/StatusCodeInterface.php`
- Create: `src/ExceptionHandlerInterface.php`
- Create: `src/ExceptionHandler.php`
- Test: `tests/ExceptionHandlerTest.php`

**Interfaces:**
- Consumes: `Errata\Mode` (Task 1), `Errata\Document\Problem` (Task 6), `Errata\Path\PathRelativizer` (Task 4), `Errata\Trace\SourceContext` (Task 2), `Errata\Trace\ArgumentSanitizer` (Task 3), `Errata\Trace\TraceFactory` (Task 7).
- Produces:
  - `Errata\Http\StatusCodeInterface` — `getStatusCode(): int`.
  - `Errata\ExceptionHandler` — `__construct(Mode $mode, ?string $projectDir = null, int $traceLimit = 30)`, `handle(Throwable $exception): Problem`.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Errata\Document\Problem;
use Errata\Document\Trace;
use Errata\Mode;
use Errata\ExceptionHandler;
use Errata\Http\StatusCodeInterface;
use Throwable;

#[CoversClass(ExceptionHandler::class)]
final class ExceptionHandlerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    public function testMinimalGivesTheStatusPhraseTheShortClassNameAndTheCode(): void
    {
        $problem = $this->handler(Mode::Minimal)->handle(new RuntimeException('secret detail', 7));

        $this->assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Internal Server Error',
                'status' => 500,
                'code' => 7,
                'detail' => 'RuntimeException',
            ],
            $problem->jsonSerialize(),
        );
    }

    public function testMinimalNeverLeaksTheMessageOrLocation(): void
    {
        $json = json_encode($this->handler(Mode::Minimal)->handle(new RuntimeException('secret detail')));

        $this->assertIsString($json);
        $this->assertStringNotContainsString('secret detail', $json);
        $this->assertStringNotContainsString('ExceptionHandlerTest', $json);
    }

    public function testFullCarriesTheMessageLocationAndWindow(): void
    {
        $problem = $this->handler(Mode::Full)->handle(new RuntimeException('boom'));

        $this->assertSame('RuntimeException: boom', $problem->detail);
        $this->assertSame('tests/ExceptionHandlerTest.php', $problem->file);
        $this->assertIsInt($problem->line);
        $this->assertNotSame([], $problem->source);
        $this->assertInstanceOf(Trace::class, $problem->trace);
        $this->assertStringNotContainsString('truncated', (string) json_encode($problem));
        $this->assertNull($problem->previous);
    }

    public function testItUsesTheStatusCodeInterfaceWhenInRange(): void
    {
        $problem = $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(404));

        $this->assertSame(404, $problem->status);
    }

    public function testItFallsBackToInternalServerErrorForATooLowStatus(): void
    {
        $this->assertSame(500, $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(200))->status);
    }

    public function testItFallsBackToInternalServerErrorForATooHighStatus(): void
    {
        $this->assertSame(500, $this->handler(Mode::Minimal)->handle(new ExceptionHandlerStatusFixture(600))->status);
    }

    public function testMinimalOmitsTheCauseButFullNestsIt(): void
    {
        $exception = new RuntimeException('outer', 0, new LogicException('inner'));

        $this->assertNull($this->handler(Mode::Minimal)->handle($exception)->previous);

        $previous = $this->handler(Mode::Full)->handle($exception)->previous;

        $this->assertInstanceOf(Problem::class, $previous);
        $this->assertSame('LogicException: inner', $previous->detail);
        $this->assertSame('Internal Server Error', $previous->title);
    }

    public function testItNestsEveryLinkOfALongCauseChain(): void
    {
        $exception = new RuntimeException('link-0');

        for ($i = 1; $i < 6; $i++) {
            $exception = new RuntimeException('link-' . $i, 0, $exception);
        }

        $problem = $this->handler(Mode::Full)->handle($exception);
        $links = 1;

        while ($problem->previous instanceof Problem) {
            $problem = $problem->previous;
            $links++;
        }

        $this->assertSame(6, $links);
        $this->assertSame('RuntimeException: link-0', $problem->detail);
    }

    public function testItResolvesStatusesInsideTheChainIndependently(): void
    {
        $exception = new RuntimeException('outer', 0, new ExceptionHandlerStatusFixture(409));

        $problem = $this->handler(Mode::Full)->handle($exception);

        $this->assertSame(500, $problem->status);
        $this->assertSame(409, $problem->previous?->status);
    }

    private function handler(Mode $mode): ExceptionHandler
    {
        return new ExceptionHandler($mode, projectDir: $this->root);
    }
}

final class ExceptionHandlerStatusFixture extends RuntimeException implements StatusCodeInterface
{
    public function __construct(private readonly int $status)
    {
        parent::__construct('status fixture');
    }

    #[Override]
    public function getStatusCode(): int
    {
        return $this->status;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/ExceptionHandlerTest.php`
Expected: FAIL — `Class "Errata\ExceptionHandler" not found`.

- [ ] **Step 3: Write the implementation**

`src/Http/StatusCodeInterface.php`:

```php
<?php declare(strict_types=1);

namespace Errata\Http;

/**
 * Implemented by exceptions that carry their own HTTP status code.
 *
 * Values outside 400..599 are ignored in favour of 500.
 *
 * @api
 */
interface StatusCodeInterface
{
    public function getStatusCode(): int;
}
```

`src/ExceptionHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Errata;

use Override;
use Errata\Document\Problem;
use Errata\Http\StatusCodeInterface;
use Errata\Path\PathRelativizer;
use Errata\Trace\ArgumentSanitizer;
use Errata\Trace\SourceContext;
use Errata\Trace\TraceFactory;
use Throwable;

/**
 * Maps a throwable to a problem document for the active mode.
 *
 * @api
 */
final class ExceptionHandler implements ExceptionHandlerInterface
{
    private const int DEFAULT_TRACE_LIMIT = 30;

    private readonly PathRelativizer $relativizer;

    private readonly SourceContext $source;

    private readonly TraceFactory $trace;

    /**
     * A negative cap is clamped rather than trusted: `array_slice()`
     * treats a negative length as a count from the end, so it cannot
     * mean "at most N frames".
     */
    public function __construct(
        private readonly Mode $mode,
        ?string $projectDir = null,
        int $traceLimit = self::DEFAULT_TRACE_LIMIT,
    ) {
        if ($traceLimit < 0) {
            $traceLimit = 0;
        }

        $this->relativizer = new PathRelativizer($projectDir);
        $this->source = new SourceContext();
        $this->trace = new TraceFactory($this->relativizer, $this->source, new ArgumentSanitizer(), $traceLimit);
    }

    #[Override]
    public function handle(Throwable $exception): Problem
    {
        $status = self::status($exception);

        if ($this->mode === Mode::Minimal) {
            return Problem::minimal($exception, $status);
        }

        $previous = $exception->getPrevious();

        /** @var list<array<string, mixed>> $trace */
        $trace = $exception->getTrace();

        return Problem::development(
            exception: $exception,
            status: $status,
            file: $this->relativizer->relativize($exception->getFile()),
            line: $exception->getLine(),
            source: $this->source->window($exception->getFile(), $exception->getLine()),
            trace: $this->trace->frames($trace),
            previous: $previous === null ? null : $this->handle($previous),
        );
    }

    /**
     * Only 4xx and 5xx are meaningful for a problem document, so a buggy
     * interface implementation falls back to 500 rather than lying.
     */
    private static function status(Throwable $exception): int
    {
        if ($exception instanceof StatusCodeInterface) {
            $status = $exception->getStatusCode();

            if ($status >= 400 && $status <= 599) {
                return $status;
            }
        }

        return 500;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/ExceptionHandlerTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
composer run fix
git add src/Http/StatusCodeInterface.php src/ExceptionHandler.php tests/ExceptionHandlerTest.php
git commit -m "feat: map throwables to problem documents per mode"
```

---

### Task 9: ExceptionMiddleware

**Files:**
- Create: `src/Middleware/ExceptionMiddleware.php`
- Test: `tests/Middleware/ExceptionMiddlewareTest.php`
- Create: `tests/Fixtures/source/utf8_failure.php`

**Interfaces:**
- Consumes: `Errata\ExceptionHandlerInterface` and `Errata\ExceptionHandler` (Task 8), `Errata\Http\StatusCodeInterface` (Task 8), `Errata\Document\Problem` (Task 6), `Psr\Http\Message\ResponseFactoryInterface`, `Psr\Http\Server\MiddlewareInterface`, `Psr\Http\Server\RequestHandlerInterface`, `Psr\Log\LoggerInterface`, `Nyholm\Psr7\Factory\Psr17Factory` (test only).
- Produces: `Errata\Middleware\ExceptionMiddleware` — `__construct(ResponseFactoryInterface $responseFactory, ExceptionHandlerInterface $handler, ?LoggerInterface $logger = null, string $logLevel = LogLevel::ERROR)`, `process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface` returning the downstream response untouched when nothing is thrown.

- [ ] **Step 1: Create the invalid-UTF-8 fixture**

The file must contain raw bytes that are not valid UTF-8, on the line `±2` above the `throw`, so the origin window includes it:

```bash
printf '<?php declare(strict_types=1);\n\nfunction errata_fixture_utf8_failure(): never\n{\n    // \xff\xfe invalid utf8 \x80\n    throw new RuntimeException("invalid utf8 fixture");\n}\n' > tests/Fixtures/source/utf8_failure.php
php -r 'var_dump(mb_check_encoding(file_get_contents("tests/Fixtures/source/utf8_failure.php"), "UTF-8"));'
```

Expected: `bool(false)` — the file is deliberately not valid UTF-8.

- [ ] **Step 2: Write the failing test**

```php
<?php declare(strict_types=1);

namespace Errata\Tests\Middleware;

use Error;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Errata\Document\Problem;
use Errata\Mode;
use Errata\ExceptionHandler;
use Errata\ExceptionHandlerInterface;
use Errata\Http\StatusCodeInterface;
use Errata\Middleware\ExceptionMiddleware;
use Stringable;
use Throwable;

require_once __DIR__ . '/../Fixtures/source/utf8_failure.php';

#[CoversClass(ExceptionMiddleware::class)]
final class ExceptionMiddlewareTest extends TestCase
{
    private Psr17Factory $factory;

    private string $root;

    private string $errorLog;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->root = dirname(__DIR__, 2);
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'errata');
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
    }

    public function testItReturnsTheDownstreamResponseUntouched(): void
    {
        $expected = $this->factory->createResponse(204);
        $response = $this->middleware()->process($this->request(), $this->returns($expected));

        $this->assertSame($expected, $response);
    }

    public function testItRespondsWithAProblemDocumentOnFailure(): void
    {
        $response = $this->middleware()->process($this->request(), $this->throws(new RuntimeException('boom', 5)));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));

        $document = $this->document($response);

        $this->assertSame('about:blank', $document['type']);
        $this->assertSame('Internal Server Error', $document['title']);
        $this->assertSame('RuntimeException', $document['detail']);
        $this->assertSame(500, $document['status']);
        $this->assertSame(5, $document['code']);
    }

    public function testMinimalOmitsFullMembers(): void
    {
        $response = $this->middleware(Mode::Minimal)->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(['type', 'title', 'status', 'code', 'detail'], array_keys($this->document($response)));
    }

    public function testFullIncludesTheTraceAndOrigin(): void
    {
        $response = $this->middleware(Mode::Full)->process($this->request(), $this->throws(new RuntimeException('boom')));

        $document = $this->document($response);

        $this->assertSame('RuntimeException: boom', $document['detail']);
        $this->assertSame('tests/Middleware/ExceptionMiddlewareTest.php', $document['file']);
        $this->assertIsArray($document['source']);
        $this->assertNotSame([], $document['source']);
        $this->assertIsArray($document['trace']);
    }

    public function testItUsesTheStatusFromTheException(): void
    {
        $response = $this->middleware()->process($this->request(), $this->throws(new MiddlewareStatusFixture()));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(422, $this->document($response)['status']);
    }

    public function testItCatchesErrorsAsWellAsExceptions(): void
    {
        $response = $this->middleware()->process($this->request(), $this->throws(new Error('broken')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal Server Error', $this->document($response)['title']);
        $this->assertSame('Error', $this->document($response)['detail']);
    }

    public function testItLogsTheExceptionWithRequestContext(): void
    {
        $logger = new MiddlewareTestLogger();
        $this->middleware(logger: $logger)->process($this->request(), $this->throws(new RuntimeException('boom', 0, null)));

        $this->assertSame(1, $logger->calls);
        $this->assertSame(LogLevel::ERROR, $logger->level);
        $this->assertSame('boom', $logger->message);
        $this->assertSame('GET', $logger->method);
        $this->assertSame('/things', $logger->path);
        $this->assertSame(500, $logger->status);
        $this->assertInstanceOf(RuntimeException::class, $logger->exception);
    }

    public function testItLogsTheDebugLevelWhenConfigured(): void
    {
        $logger = new MiddlewareTestLogger();
        $this->middleware(logger: $logger, logLevel: LogLevel::CRITICAL)->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(LogLevel::CRITICAL, $logger->level);
    }

    public function testLoggingIsOptional(): void
    {
        $response = $this->middleware(logger: null)->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testAThrowingLoggerDoesNotBreakTheResponse(): void
    {
        $response = $this->middleware(logger: new MiddlewareThrowingLogger())->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('RuntimeException', $this->document($response)['detail']);
        $this->assertStringContainsString('logger failed', $this->errorLogContents());
    }

    public function testAThrowingHandlerStillProducesAProblemDocument(): void
    {
        $middleware = new ExceptionMiddleware(
            $this->factory,
            new MiddlewareThrowingHandler(),
            new MiddlewareThrowingLogger(),
        );

        $response = $middleware->process($this->request(), $this->throws(new RuntimeException('boom')));

        $this->assertSame(500, $response->getStatusCode());

        $document = $this->document($response);

        $this->assertSame(['type', 'title', 'status', 'code', 'detail'], array_keys($document));
        $this->assertSame('RuntimeException', $document['detail']);
        $this->assertStringContainsString('handler failed', $this->errorLogContents());
        $this->assertStringContainsString('logger failed', $this->errorLogContents());
    }

    public function testInvalidUtf8InASourceFileStillProducesDecodableJson(): void
    {
        $exception = $this->utf8Failure();
        $response = $this->middleware(Mode::Full)->process($this->request(), $this->throws($exception));
        $body = (string) $response->getBody();

        $this->assertIsArray($this->document($response));
        $this->assertStringNotContainsString("\xff", $body);
        $this->assertStringContainsString('\ufffd', strtolower($body));
    }

    private function middleware(
        Mode $mode = Mode::Minimal,
        ?LoggerInterface $logger = null,
        string $logLevel = LogLevel::ERROR,
    ): ExceptionMiddleware {
        return new ExceptionMiddleware(
            $this->factory,
            new ExceptionHandler($mode, projectDir: $this->root),
            $logger,
            $logLevel,
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequest('GET', 'https://api.example.com/things'));
    }

    /**
     * @return array<string, mixed>
     */
    private function document(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function errorLogContents(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    private function utf8Failure(): Throwable
    {
        try {
            errata_fixture_utf8_failure();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The fixture was expected to throw.');
    }

    private function returns(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    private function throws(Throwable $exception): RequestHandlerInterface
    {
        return new class($exception) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $exception) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->exception;
            }
        };
    }
}

final class MiddlewareStatusFixture extends RuntimeException implements StatusCodeInterface
{
    #[Override]
    public function getStatusCode(): int
    {
        return 422;
    }
}

final class MiddlewareTestLogger extends AbstractLogger
{
    public int $calls = 0;

    public string $level = '';

    public string $message = '';

    public string $method = '';

    public string $path = '';

    public int $status = 0;

    public ?Throwable $exception = null;

    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->calls++;
        $this->level = is_string($level) ? $level : '';
        $this->message = (string) $message;
        $this->method = self::stringFrom($context, 'method');
        $this->path = self::stringFrom($context, 'path');
        $this->status = self::intFrom($context, 'status');

        $exception = $context['exception'] ?? null;

        $this->exception = $exception instanceof Throwable ? $exception : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function stringFrom(array $context, string $key): string
    {
        $value = $context[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function intFrom(array $context, string $key): int
    {
        $value = $context[$key] ?? null;

        return is_int($value) ? $value : 0;
    }
}

final class MiddlewareThrowingLogger extends AbstractLogger
{
    #[Override]
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('logger is broken');
    }
}

final class MiddlewareThrowingHandler implements ExceptionHandlerInterface
{
    #[Override]
    public function handle(Throwable $exception): Problem
    {
        throw new RuntimeException('handler is broken');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Middleware/ExceptionMiddlewareTest.php`
Expected: FAIL — `Class "Errata\Middleware\ExceptionMiddleware" not found`.

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Errata\Middleware;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Errata\Document\Problem;
use Errata\ExceptionHandlerInterface;
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
                'errata: handler failed while reporting %s: %s',
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
                'errata: logger failed while reporting %s: %s',
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
            error_log(sprintf('errata: could not encode the problem document: %s', $failure->getMessage()));

            return [500, self::FALLBACK_BODY];
        }

        /** @var string $body */
        return [$problem->status, $body];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Middleware/ExceptionMiddlewareTest.php`
Expected: PASS, 13 tests.

Two assertions exist to prove failure modes, not to test plumbing:

- `testAThrowingHandlerStillProducesAProblemDocument` injects a handler that throws (`MiddlewareThrowingHandler`), so the middleware's fallback runs: the body must still be the minimal shape and the secondary failure must reach the error log. This branch is unreachable through `ExceptionHandler` itself, because every collaborator is `final` and the handler is total — which is exactly why `ExceptionHandlerInterface` exists (decision 27).
- `testInvalidUtf8InASourceFileStillProducesDecodableJson` fails if `JSON_INVALID_UTF8_SUBSTITUTE` is dropped from the flag set.

- [ ] **Step 6: Commit**

```bash
composer run fix
git add src/Middleware/ExceptionMiddleware.php tests/Middleware/ExceptionMiddlewareTest.php tests/Fixtures/source/utf8_failure.php
git commit -m "feat: answer uncaught throwables with a problem document"
```

---

### Task 10: Documentation and end-to-end verification

**Files:**
- Modify: `README.md`
- Create: `CHANGELOG.md`

**Interfaces:**
- Consumes: everything above.
- Produces: user-facing documentation; no code.

- [ ] **Step 1: Replace the README**

```markdown
# Errata

♞♘ Exceptional error handler for JSON APIs.

Turns any uncaught `Throwable` into an RFC 9457 `application/problem+json`
document, logs it through PSR-3, and never leaks production internals.

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
    new Psr17Factory(),
    new ExceptionHandler(Mode::fromEnv()),
    $logger,   // optional PSR-3 logger; omit to disable logging
);
```

Register it as the outermost PSR-15 middleware so it sees everything the
rest of the stack throws.

## Modes

`Mode::fromEnv()` reads `APP_ENV`, then `APP_DEBUG`:

| `APP_ENV` | `APP_DEBUG` | Mode |
|-----------|-------------|------|
| `dev`, `development`, `local` | any | full |
| anything else | `1`, `true`, `on`, `yes` | full |
| anything else | anything else | minimal |

Anything unrecognised, including unset variables, is minimal: a mode
that leaks internals is never chosen by accident. Pass an
`Mode` case explicitly to bypass detection.

`title` is the recommended HTTP status phrase for the document's status,
as RFC 9457 §4.2.1 requires when `type` is `about:blank`. The exception
identity travels in `detail`: minimal responses carry the short exception
class name, and full responses set `detail` to `class: message`.

Minimal responses:

```json
{"type":"about:blank","title":"Internal Server Error","status":500,"code":0,"detail":"RuntimeException"}
```

Full responses add `file`, `line`, `source`, `trace`, and, for chained
exceptions, `previous`.

## Traces

Paths are relative to the application directory, which defaults to the
Composer root package directory (`getcwd()` without Composer) and can be
overridden with the second `ExceptionHandler` argument.

The exception's origin and every frame carry a five-line source window
(the reported line ±2), clamped to the file, with blank lines removed and
original line numbers preserved. Traces are capped at 30 frames
(`traceLimit`), keeping the frames nearest the throw; a capped trace is
flagged with `truncated`.

Frame arguments are included, truncated to depth 5, 50 items, and 500
bytes per string. Objects are reduced to a class name plus at most 50
public properties, with the remainder reported by the same
`"*truncated*": "N more items"` marker used for maps.
`#[\SensitiveParameter]` values are redacted, and `__toString()` is
never called.

**Arguments require `zend.exception_ignore_args=Off`.** It defaults to
`Off`, and `php.ini-development` sets `Off`, but `php.ini-production`
sets `On`, which removes arguments from every trace PHP produces. An
application running full mode with a production `php.ini` will
see frames with no `args` member.

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

Every caught throwable is logged at `LogLevel::ERROR` by default (the
level is the fourth `ExceptionMiddleware` argument) with the throwable
under the conventional `exception` key, plus `method`, `path`, and
`status`. Headers, query strings, and bodies are never logged. A logger
that itself throws is reported with `error_log()` and the response is
still sent.

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
```

- [ ] **Step 2: Create the changelog**

```markdown
# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- `ExceptionMiddleware`, a PSR-15 middleware that answers any uncaught
  `Throwable` with an RFC 9457 `application/problem+json` document.
- `ExceptionHandler`, which maps a `Throwable` to a `Problem` document.
- `Mode` with full and minimal modes, detected from
  `APP_ENV` and `APP_DEBUG`.
- Full documents with message, origin, five-line source windows,
  relative paths, traces, and chained causes.
- Trace frames with truncated frame arguments; `#[\SensitiveParameter]`
  values are redacted and `__toString()` is never invoked.
- `Http\StatusCodeInterface` for exceptions that carry their own HTTP
  status code.
- Optional PSR-3 logging of every caught throwable.
- Depends on `codeinc/http-reason-phrase-lookup` for the HTTP status
  phrases used as the document `title`.
```

- [ ] **Step 3: Run the whole suite with coverage**

Run: `composer run test`
Expected: PASS and `100.00%` covered. Any uncovered line means a branch in `src/` has no test — add the test rather than lowering the threshold. If a branch is genuinely unreachable, delete the branch.

- [ ] **Step 4: Run the full verification**

Run: `composer run verify`
Expected: PASS for check, lint, analyze, and test. Lint warnings are acceptable (the gate only fails on errors); if `analyze` reports errors, fix them.

- [ ] **Step 5: Prove it end to end with a throwaway script**

Write `/tmp/errata-smoke.php` (outside the repository, so there is nothing to clean up):

```php
<?php declare(strict_types=1);

require '/Users/woodygilk/Code/joust/errata/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Errata\Mode;
use Errata\ExceptionHandler;
use Errata\Middleware\ExceptionMiddleware;

final class Boom
{
    public function explode(string $token): never
    {
        throw new RuntimeException('the widget fell over', 42);
    }
}

final class Exploding implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        (new Boom())->explode('token-value');

        throw new RuntimeException('unreachable');
    }
}

$factory = new Psr17Factory();

foreach ([Mode::Minimal, Mode::Full] as $mode) {
    $middleware = new ExceptionMiddleware($factory, new ExceptionHandler($mode));
    $response = $middleware->process(new ServerRequest('GET', 'https://api.example.com/widgets/1'), new Exploding());

    printf(
        "%s => HTTP %d %s\n%s\n\n",
        $mode->value,
        $response->getStatusCode(),
        $response->getHeaderLine('Content-Type'),
        (string) $response->getBody(),
    );
}
```

Run: `php /tmp/errata-smoke.php`

Expected: two bodies. Minimal prints exactly
`{"type":"about:blank","title":"Internal Server Error","status":500,"code":42,"detail":"RuntimeException"}`.
Full prints the same keys plus `file` (`/tmp/errata-smoke.php`
is outside the project directory, so it stays absolute — that is the
documented behaviour), `line`, `source` with the `throw` line, and `trace`
whose innermost frame is the call to `Boom::explode`, with `args`
containing `"token-value"` (arguments appear when
`zend.exception_ignore_args` is `Off`, which is the default). Read the
output: if the minimal body
contains any full member, Task 6 is wrong.

- [ ] **Step 6: Commit**

```bash
composer run fix
git add README.md CHANGELOG.md
git commit -m "docs: document usage, modes, traces, and status codes"
```

---

## Completion check

Before declaring the plan done, confirm each of these:

- `composer run verify` passes; coverage is exactly 100%.
- Every file listed under `src/` in the spec exists: `Mode.php`, `ExceptionHandlerInterface.php`, `ExceptionHandler.php`, `Http/StatusCodeInterface.php`, `Middleware/ExceptionMiddleware.php`, `Document/{Problem,Trace,Frame,SourceLine,SanitizedObject,SanitizedMap}.php`, `Path/PathRelativizer.php`, `Trace/{TraceFactory,SourceContext,ArgumentSanitizer}.php`.
- `git grep -n 'vendor' src/` returns nothing: no vendor detection survives anywhere in the implementation.
- The smoke script's minimal body is exactly `{"type":"about:blank","title":"Internal Server Error","status":500,"code":42,"detail":"RuntimeException"}`.
