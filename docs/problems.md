# Problems

A problem is an [RFC 9457][] problem document. Errata models it with `Errata\Problem`, and ships fixed-status
variants under the `Errata\Http` namespace.

[RFC 9457]: https://www.rfc-editor.org/rfc/rfc9457

## Problem

`Errata\Problem` implements `JsonSerializable` and carries the five standard members: `type`, `title`, `detail`,
`status`, and `instance`.

```php
use Errata\Problem;

$problem = new Problem(
    type: 'https://example.com/faq/order-quantity-minimums',
    title: 'Minimum Quantity',
    detail: 'The minimum order quantity for this product is 100 units.',
    status: 400,
    instance: '/orders/1234',
);

$problem->extend('refcode', 'MPQ.100');

echo json_encode($problem, JSON_PRETTY_PRINT);
```

Will produce a JSON document like:

```json
{
  "type": "https://example.com/faq/order-quantity-minimums",
  "title": "Minimum Quantity",
  "detail": "The minimum order quantity for this product is 100 units.",
  "status": 400,
  "instance": "/orders/1234",
  "refcode": "MPQ.100"
}
```

### Members

All five standard members are public, nullable, and mutable. Assignments normalize as follows:

- **type, title, detail, instance**: `''` normalizes to `null`.
- **status**: `0` normalizes to `null`, and any other value must be between `100` and `599`.

Setting `status` to a value outside `100` to `599` throws `Errata\ProblemException`.

The `extensions` property is read-only from the outside.

### Extension members

`extend($name, $value)` adds an extension member and returns the same problem for chaining. Extension names cannot
be empty and cannot be one of the reserved standard members (`type`, `title`, `detail`, `status`, or `instance`).
Both cases throw `Errata\ProblemException`. Extension values are serialized as given, except that `null` values are
dropped from the output.

`toArray()` and `jsonSerialize()` produce the document with the five standard members in order, nulls omitted,
followed by extension members in insertion order.

## ProblemException

`Errata\ProblemException` extends `LogicException` and is thrown for:

- an empty extension name
- an extension name using a reserved standard member
- a status outside `100` to `599`

The HTTP problem documents described below will also throw when attempting to overwrite `title` or `status`.

## HTTP problems

The `Errata\Http` namespace ships fixed-status problem documents. `Errata\Http\HttpProblem` is the abstract base;
its constructor takes only `type`, `detail`, and `instance`. Each concrete class pins an IANA-correct `title` and
`status` that cannot be overwritten. Assigning to either throws `Errata\ProblemException`.

```php
use Errata\Http\Client\NotFound;

$problem = new NotFound(
    type: 'https://example.com/faq/missing-user',
    detail: 'No user with that id exists.',
    instance: '/users/1234',
);
```

These documents serialize the same way as `Problem` and can all be returned as HTTP responses when encoded to JSON.

### Client problems (`Errata\Http\Client\`)

| Class | Status | Title |
| --- | --- | --- |
| `BadRequest` | 400 | Bad Request |
| `Unauthorized` | 401 | Unauthorized |
| `PaymentRequired` | 402 | Payment Required |
| `Forbidden` | 403 | Forbidden |
| `NotFound` | 404 | Not Found |
| `MethodNotAllowed` | 405 | Method Not Allowed |
| `NotAcceptable` | 406 | Not Acceptable |
| `ProxyAuthenticationRequired` | 407 | Proxy Authentication Required |
| `RequestTimeout` | 408 | Request Timeout |
| `Conflict` | 409 | Conflict |
| `Gone` | 410 | Gone |
| `LengthRequired` | 411 | Length Required |
| `PreconditionFailed` | 412 | Precondition Failed |
| `ContentTooLarge` | 413 | Content Too Large |
| `UriTooLong` | 414 | URI Too Long |
| `UnsupportedMediaType` | 415 | Unsupported Media Type |
| `RangeNotSatisfiable` | 416 | Range Not Satisfiable |
| `ExpectationFailed` | 417 | Expectation Failed |
| `ImATeapot` | 418 | I'm a teapot |
| `MisdirectedRequest` | 421 | Misdirected Request |
| `UnprocessableContent` | 422 | Unprocessable Content |
| `Locked` | 423 | Locked |
| `FailedDependency` | 424 | Failed Dependency |
| `TooEarly` | 425 | Too Early |
| `UpgradeRequired` | 426 | Upgrade Required |
| `PreconditionRequired` | 428 | Precondition Required |
| `TooManyRequests` | 429 | Too Many Requests |
| `RequestHeaderFieldsTooLarge` | 431 | Request Header Fields Too Large |
| `UnavailableForLegalReasons` | 451 | Unavailable For Legal Reasons |

### Server problems (`Errata\Http\Server\`)

| Class | Status | Title |
| --- | --- | --- |
| `InternalServerError` | 500 | Internal Server Error |
| `NotImplemented` | 501 | Not Implemented |
| `BadGateway` | 502 | Bad Gateway |
| `ServiceUnavailable` | 503 | Service Unavailable |
| `GatewayTimeout` | 504 | Gateway Timeout |
| `HttpVersionNotSupported` | 505 | HTTP Version Not Supported |
| `VariantAlsoNegotiates` | 506 | Variant Also Negotiates |
| `InsufficientStorage` | 507 | Insufficient Storage |
| `LoopDetected` | 508 | Loop Detected |
| `NotExtended` | 510 | Not Extended |
| `NetworkAuthenticationRequired` | 511 | Network Authentication Required |
