# AGENTS.md

`folklore/laravel-mediatheque` is a Laravel package that stores media (video, audio, images, documents, fonts, captions), probes them, produces their renditions through interchangeable transcoders, and serves them through public or temporary URLs.

**Language.** This is a public repository. Everything written here is in **English**: code, comments, docs, commit messages, issues and their comments, pull requests and reviews. Never copy private details from the projects that use this package (product plans, infrastructure, internal docs, client names). Report security problems privately through a GitHub security advisory, never in a public issue, PR or commit message.

**Branches.** This is `v2.x`, where 2.0 is developed. 1.x is maintained on `v1.1`, with its own `AGENTS.md`: read that one when you work on 1.x.

**Read [`docs/`](docs/README.md) first.** Its architecture and decisions are the design of 2.0. A change that departs from them adds or amends a decision in `docs/decisions/` in the same pull request.

**Roadmap.** The overall plan is tracked in [#1](https://github.com/folkloreinc/laravel-mediatheque/issues/1), the 2.0 kickoff in [#23](https://github.com/folkloreinc/laravel-mediatheque/issues/23), and the phases and alphas in [`docs/roadmap.md`](docs/roadmap.md). Create a sub-issue when starting an item, and check the item in the roadmap in the pull request that closes it.

## Who depends on this package

- The pilot project requires 2.0 pre-releases (`^2.0@alpha`) and pulls each alpha from its own needs.
- About forty applications use 1.x, most through `folklore/laravel-folklore`. They extend the models, use the `HasMedias` trait, store `medias://` references and read files by handle. 2.0 keeps their tables and a compatibility layer for their code ([decisions/0002](docs/decisions/0002-evolve-the-schema-in-place.md)), so treat the 1.x API listed in [`docs/upgrade.md`](docs/upgrade.md) as something the compatibility layer must keep working.
- `laravel-image` 2, `laravel-eclair` and Panneau 5 implement or consume contracts of this package. A change to a contract they use is designed with them, in linked issues ([decisions/0009](docs/decisions/0009-package-boundaries.md)).

## Non-negotiable rules

1. **Contracts first.** Each public contract is written with its tests before its implementation. Until the release candidate, an alpha can break the previous one; every break is listed in the CHANGELOG.
2. **Depend on contracts, never on concrete models.** Applications set their own models in `config('mediatheque.models')`; package code resolves them through the contracts.
3. **Tenant-agnostic.** The package knows nothing about tenants, organisations or users. Applications add their own columns, extend the models and pass their attributes to the path formatter.
4. **Secure by default.** Detect types from the content, never from the client's file name or extension. Validate size and type, sanitize SVG, keep HTTP routes off until the application registers them behind its own authorization, and never let a request act on a media it was not authorized for.
5. **Safe to retry.** Jobs, webhooks and recorder calls can run twice and arrive out of order. They must not duplicate files, must clean up their temporary files, and statuses only move forward ([decisions/0012](docs/decisions/0012-pipeline-runs-recorder-and-probe.md)).
6. **No hardcoded binaries, URLs or secrets.** Binary paths and credentials come from config and env.
7. **No `serialize()`** for anything stored. Definitions and snapshots are JSON.

## Repository map

```
src/                 PSR-4: Folklore\Mediatheque\ (layout in docs/architecture.md)
config/              mediatheque.php, the package defaults
database/migrations/ Published by mediatheque:install; the package doesn't load them
docs/                Current state of 1.x, architecture, decisions, roadmap, upgrade guide
tests/
  Contracts/         Contract tests (traits): every implementation of a contract runs them
  Feature/           Orchestra Testbench (SQLite in memory, or MySQL with DB_DRIVER=mysql)
  Unit/
  fixtures/          Sample media, and the 1.x schema and config for the upgrade tests
```

## Style

- PHP 8.3+ and Laravel 12+ ([decisions/0001](docs/decisions/0001-supported-versions.md)): don't use language features newer than PHP 8.3. Declare every `illuminate/*` component the code uses.
- Typehint every parameter, return value and property. Prefer enums and readonly value objects to strings and arrays.
- Format with [Laravel Pint](https://laravel.com/docs/pint) (`pint.json`, `laravel` preset): run `composer format` before committing. CI runs `composer lint` (`pint --test`).
- Static analysis runs with [Larastan](https://github.com/larastan/larastan) at the maximum level on `src`, `config` and `tests` (`phpstan.neon.dist`): `composer analyse`. There is no baseline: fix every error. Ignore an error only with a comment that says why, scoped to its identifier and path.

## Tests

```bash
composer install
composer test
```

- **Every change comes with tests**: contract tests for each contract (a trait in `tests/Contracts/`, used by a test of each implementation, the fake's included), a regression test for each bug fix, a feature test on a fixture for each operation of a transcoder.
- Tests that need a binary (ffmpeg, Imagick…) or an external service are skipped when it is not available, and CI installs what they need.
- The `tests/fixtures/v1/` files are the 1.x schema and config as they are on `v1.1`. Don't edit them to make a test pass.
- The tests run on SQLite in memory. To run them on MySQL, as CI also does, set `DB_DRIVER=mysql` with `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. A test never creates a table: the application's tables it needs are in `tests/Support/migrations`.
- Before pushing, run `composer lint`, `composer analyse` and `composer test`, and say in the PR what you ran and what could not run.

## Workflow

- Branch from `v2.x`, named `<type>/<issue>-<slug>`, and open a pull request to `v2.x`. Nothing is pushed directly to `v2.x`.
- GitHub Actions (`.github/workflows/ci.yml`) runs Pint, Larastan and the test suite (PHP 8.3–8.5 × Laravel 12 and 13 on SQLite, and on MySQL 8.4) on every pull request and on `v2.x`. **Merge only when CI is green.** Never skip, disable or weaken a test to get there.
- `v1.1` is never merged into `v2.x`: their code has nothing in common any more. A 1.x fix that matters to 2.0 is written again for 2.0, with its own test.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`…).
- Every pull request with a user-visible effect adds a line under `[Unreleased]` in `CHANGELOG.md`, with its issue number. A breaking change goes under **Breaking changes**, and says what to change.

## Releases

2.0 ships as tagged pre-releases (`v2.0.0-alpha.N`, then `-beta.N` and `-rc.N`), so the pilot project requires `^2.0@alpha` rather than `v2.x-dev`. A release is only tagged from a commit of `v2.x` whose CI passed.

1. Open a pull request `release/<issue>-v2.0.0-alpha.N` to `v2.x` that renames `[Unreleased]` in `CHANGELOG.md` to `[2.0.0-alpha.N] - YYYY-MM-DD`, adds an empty `[Unreleased]` above it and updates the compare links at the bottom. Check the alpha's items in `docs/roadmap.md`.
2. Merge it once its CI is green and it is approved.
3. CI runs again on `v2.x`. When its lint and test jobs pass, its **Release** job reads the first dated section of `CHANGELOG.md` and, if that version has no tag yet, creates the tag on that commit and a GitHub release with the section as its notes, marked as a pre-release for alphas, betas and RCs. Packagist picks up the tag. If CI fails, nothing is tagged: fix `v2.x` in a new pull request, and the release happens when CI passes.

A dated section in `CHANGELOG.md` is a release: only a release pull request adds one. The Release job refuses a version that is not 2.x. Never create a 2.x tag by hand or from another branch.
