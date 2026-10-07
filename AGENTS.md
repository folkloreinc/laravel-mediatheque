# AGENTS.md

`folklore/laravel-mediatheque` is a Laravel package that stores media (images, video, audio, documents, fonts), extracts their metadata and runs processing pipelines on them (transcoding, thumbnails, HLS, web fonts…).

**Language.** This is a public repository. Everything written here is in **English**: code, comments, docs, commit messages, issues and their comments, pull requests and reviews. Never copy private details from the projects that use this package (product plans, infrastructure, internal docs, client names). Report security problems privately through a GitHub security advisory, never in a public issue, PR or commit message.

**Roadmap.** The stabilization and modernization plan is tracked in [#1](https://github.com/folkloreinc/laravel-mediatheque/issues/1). Create a sub-issue of #1 when starting one of its items, and check the item when the sub-issue is closed.

---

## Who depends on this package

- `folklore/laravel-folklore`, and through it many client projects. It extends the `Media` and `File` models, uses the `HasMedias` trait on its own models and wraps media in entities that rely on file handles (`original`, `thumbnail…`).
- Other applications built on the package, which may also extend the models.

These projects require the `v1.1.x-dev` branch. Until releases are tagged, **every push to `v1.1` reaches them on their next `composer update`**.

## Non-negotiable rules

1. **Nothing breaks in 1.x.** Consumers extend the models, use the traits and call the contracts directly, so they are public API. Keep table and column names, method signatures and file handles. Migrations are additive only. Breaking changes wait for 2.0 and come with an upgrade guide.
2. **Depend on contracts, never on concrete models.** Resolve models with `app(Contracts\Models\Media::class)` (and `File`, `Metadata`, `Pipeline`, `PipelineJob`): applications rebind them to their own subclasses.
3. **Tenant-agnostic.** The package knows nothing about workspaces, organisations or users. Applications add their own column and extend the model. Never add tenant logic here; organisation scoping for client projects belongs in `laravel-folklore`.
4. **Secure by default.** Never trust an uploaded file's client name or extension. Validate size and type, sanitize SVG, and never let a request act on a media it was not authorized for.
5. **Jobs are safe to retry.** A pipeline job can run twice (queue retries, `retry_after`). It must not duplicate files, must clean up its temporary files, and must report failure to its pipeline.
6. **No hardcoded binaries, URLs or secrets.** Binary paths and credentials come from config and env (`FFMPEG_BIN`, `FFPROBE_BIN`, `AUDIOWAVEFORM_BIN`, `IMAGICK_CONVERT_BIN`, `OTFINFO_BIN`, `AWS_MEDIACONVERT_*`).

---

## Repository map

```
src/
  config/config.php            Types, metadata readers, pipelines, sources, routes, services
  migrations/                  Tables prefixed with mediatheque.table_prefix
  routes.php                   Route::mediatheque() (upload and CRUD routes)
  Folklore/Mediatheque/        PSR-0 autoload root (namespace Folklore\Mediatheque)
    Contracts/                 Interfaces bound in the ServiceProvider
    Models/                    Media, File, Metadata, Pipeline, PipelineJob
    Support/                   Type, Pipeline, PipelineJob, FFMpegJob, ShellJob,
                               ThumbnailsJob, Definition; Traits/ (HasFiles,
                               HasMedias, HasMetadatas, HasPipelines, HasThumbnails, HasUrl)
    Types/                     Custom types (Video: animated GIF/WebP detection)
    Jobs/                      RunPipeline, RunPipelineJob; Video/, Audio/, Document/, Font/
    Services/                  FFMpeg, Imagick, AudioWaveForm, OtfInfo, MediaConvertClient,
                               Metadata (mime, extension, dimension, duration), PathFormatter…
    Sources/                   LocalSource, FilesystemSource (Laravel disks)
    Metadata/                  Metadata readers (duration, dimension, pages_count…)
    Http/                      Controllers and requests used by Router
    *Manager.php               TypeManager, PipelineManager, SourceManager, MetadataManager
tests/
  Feature/, Unit/              PHPUnit with Orchestra Testbench (SQLite in memory)
  fixture/                     Sample media files
```

## Core concepts

- A **Type** (`video`, `audio`, `image`, `document`, `font`) is detected from the file's mime type and declares its metadata readers and its default pipeline.
- A **Media** has **Files** attached by **handle**: `original` for the upload, then one handle per pipeline output (`h264`, `webm`, `hls`, `thumbnails.0`…). Consumers read files by handle, so handle names are public API.
- A **Pipeline** is a set of jobs. Each job reads the file named by `from_file` (`original` by default) and its output is attached under the job's name. A job waiting for another job's output starts when that file is attached.
- A **Source** stores files: `local` (a directory) or `filesystem` (any Laravel disk, such as S3).

## Style

- Code must keep running on the PHP and Laravel versions declared in `composer.json` for 1.x. The minimum is PHP 8.2: don't use language features newer than 8.2.
- Raising the minimum PHP or Laravel version on `v1.1` breaks every consuming project that still runs an older one. Do it only after the maintainers confirm that all active consumers already run the new minimum.
- Typehint parameters and return values when it doesn't break subclasses in consuming projects.
- Format with [Laravel Pint](https://laravel.com/docs/pint) (`pint.json`, `laravel` preset): run `composer format` before committing. CI runs `composer lint` (`pint --test`).
- Static analysis runs with [Larastan](https://github.com/larastan/larastan) (`phpstan.neon.dist`): `composer analyse`. Existing errors are listed in `phpstan-baseline.neon`; never add new ones to it. When a fix removes an error, regenerate the baseline (`vendor/bin/phpstan analyse --generate-baseline`) so it only shrinks.

## Tests

```bash
composer install
FFMPEG_BIN=$(which ffmpeg) FFPROBE_BIN=$(which ffprobe) composer test
```

- Feature tests need the real binaries: ffmpeg and ffprobe, audiowaveform, ImageMagick with the `imagick` PHP extension, and otfinfo. Their paths come from environment variables (`FFMPEG_BIN`, `FFPROBE_BIN`, `AUDIOWAVEFORM_BIN`, `IMAGICK_CONVERT_BIN`, `OTFINFO_BIN`); the defaults are in `/usr/local/bin`. For a local setup, copy `phpunit.xml.dist` to `phpunit.xml` (ignored by git) and add your paths there.
- Tests that need an external service (MediaConvert) are skipped when it is not configured.
- **Every change comes with tests**: a regression test for each bug fix, a feature test on a fixture for each new pipeline job, and tests for each new behavior.
- Before pushing, run `composer lint`, `composer analyse` and `composer test`, and say in the PR what you ran and what could not run (a missing binary, for example).

## Workflow

- `v1.1` is the maintenance branch for 1.x. Older branches (`master`, `develop`, `v1`) are not maintained.
- Open a pull request for anything with a runtime effect: it is the only review before the change reaches client projects.
- GitHub Actions (`.github/workflows/ci.yml`) runs Pint, Larastan and the test suite (PHP 8.2–8.5 × Laravel 9–13; Laravel 9 and 10 on PHP 8.2 and 8.3 only) on every pull request and on `v1.1`. **Merge only when CI is green.** Never skip, disable or weaken a test to get there.
- Commit messages follow Conventional Commits (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`…).
