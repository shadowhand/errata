# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-10-08

### Changed

- `Origin` now accepts `$appDir` and `$vendorDir` parameters directly, instead of `Root` object. This makes
  application detection more explicit and reliable, without depending on additional packages.
- `Fingerprint` now accepts an `$appDir` parameter, instead of `Root` object. This makes relative path fingerprinting
  more explicitly opt-in.

## [0.1.0] - 2026-10-05

### Added

- Initial release.

[Unreleased]: https://github.com/shadowhand/errata/compare/0.2.0...main
[0.2.0]: https://github.com/shadowhand/errata/releases/tag/0.2.0
[0.1.0]: https://github.com/shadowhand/errata/releases/tag/0.1.0
