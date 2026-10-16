# Changelog

All notable changes to `folklore/laravel-mediatheque` 2.x are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). The changes of 1.x are in [the `v1.1` changelog](https://github.com/folkloreinc/laravel-mediatheque/blob/v1.1/CHANGELOG.md).

2.0 is released as pre-releases first (`2.0.0-alpha.N`, then beta and RC). Until the release candidate, a pre-release can break the previous one: each pre-release lists its breaking changes first, under **Breaking changes**. [`docs/upgrade.md`](docs/upgrade.md) covers the upgrade from 1.x.

## [Unreleased]

### Breaking changes

- 2.x requires PHP 8.3+ and Laravel 12+ ([decisions/0001](docs/decisions/0001-supported-versions.md), #28).
- The 1.x code is removed from `v2.x`; 2.0 is rewritten behind the 1.x tables ([decisions/0002](docs/decisions/0002-evolve-the-schema-in-place.md), #29). Nothing of the 1.x API is available until the compatibility layer lands, in the beta.

### Added

- Releases are tagged by CI, from a commit of `v2.x` whose lint and test jobs passed, when `CHANGELOG.md` gets a dated section for a new version (#30).

[Unreleased]: https://github.com/folkloreinc/laravel-mediatheque/compare/v1.1.3...v2.x
