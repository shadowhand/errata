# Transforming Throwables

`Errata\ThrowableTransformer` converts a `Throwable` into a `Problem`. It is the core of the
[middleware](middleware.md), and can be used on its own.

```php
use Errata\ThrowableTransformer;

$transformer = new ThrowableTransformer();

$problem = $transformer->transform($throwable);
```

The transformer takes two optional collaborators: a `ProblemMap` that decides which problem a throwable becomes,
and an `ExtensionList` whose extensions enrich the resulting problem.

## The transformation pipeline

1. The throwable is looked up in the `ProblemMap`. When a factory matches, it is called with the throwable and its
   return value becomes the problem. When nothing matches, an `Errata\Http\Server\InternalServerError` is returned.
2. The `ExtensionList` runs, giving each extension a chance to add extension members to the problem.

## ProblemMap

`Errata\ProblemMap` maps a class name to a factory `Closure(object): Problem`. This can be used for throwables:

```php
use Errata\Http\Client\UnprocessableContent;
use Errata\ProblemMap;

$map = new ProblemMap([
    ValidationFailed::class => static fn(ValidationFailed $e): UnprocessableContent => new UnprocessableContent(detail: $e->getMessage()),
    // Or, if you prefer an object with __invoke:
    ValidationFailed::class => new UnprocessableContentFactory(),
]);
```

Lookup checks the exact class first, then parent classes nearest-first, then implemented interfaces, and returns
the first factory found. When nothing matches, `get()` returns `null` and the transformer falls back to an
`InternalServerError`.

## Extensions

An extension adds extended members to a problem from the throwable. Implement `Errata\Extension\Extension`:

```php
use Errata\Extension\Extension;
use Errata\Problem;
use Throwable;

final readonly class RequestId implements Extension
{
    public function __construct(
        /** @var non-empty-string */
        private string $key = 'request_id',
    ) {}

    public function extend(Problem $problem, Throwable $throwable): void
    {
        $problem->extend($this->key, /* the request id */);
    }
}
```

`Errata\Extension\ExtensionList` applies its extensions in order, can nest other lists (it implements `Extension`
itself), and is iterable.

```php
use Errata\Extension\ExtensionList;
use Errata\Extension\Message;
use Errata\Extension\Type;

$transformer = new ThrowableTransformer(
    extensionList: new ExtensionList(
        new Type(),
        new Message(),
    ),
);
```

### Built-in extensions

All built-in extensions live under `Errata\Extension\` and take an optional custom `key` as their first argument.

| Extension | Default key | Value |
| --- | --- | --- |
| `Message` | `message` | the throwable message |
| `Type` | `exception` | the short class name (`short: false` for the fully qualified name) |
| `Code` | `code` | the throwable code, as a string |
| `Origin` | `origin` | the first trace location inside the application root |
| `Trace` | `trace` | every trace location, in throw order |
| `Timestamp` | `timestamp` | the current time (RFC 3339 extended, UTC by default) |
| `Fingerprint` | `fingerprint` | a hash of the class, code, and application-relative file |

Additional constructor options:

- `Timestamp` takes `format` and `timezone`. `format` is a `DateTimeInterface` constant and defaults to
  `RFC3339_EXTENDED`. `timezone` defaults to `UTC`. The timestamp is taken at the moment the extension runs.
- `Fingerprint` takes `algo`, which defaults to `xxh64` and accepts any `hash()` algorithm, and a `Root`. The hash
  input joins the throwable class, code, and the file path made relative to the application root, so fingerprints
  are stable across systems.
- `Origin` takes `appDir` and `vendorDir` to allow picking an application-specific origin by finding the first
  `Location` that is inside the `appDir` and *not* inside the `vendorDir`.
- `Fingerprint` takes a `Root`.

### Trace locations

`Errata\Trace\Location` represents one entry in a throwable's stack trace. A location list starts with the
throwable's own file and line, followed by the trace frames, skipping entries without a file. Locations serialize
to JSON as `"file:line"` strings.

## Root

`Errata\Root` identifies the application directory and the vendor directory beneath it. When no directory is
given, it is detected from Composer. `isApp()` reports whether a path is inside the application but not the vendor
tree, and `relative()` makes a path relative to the application root while leaving paths outside it untouched.
`Origin` and `Fingerprint` use both.
