# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- Runnable PHP development-server demos for minimal and full responses,
  recursive object calls, and sensitive arguments and properties.
- A centered demo index with a four-link grid and a single router serving
  all demos.

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
