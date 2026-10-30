# Changelog

All notable changes to `folklore/laravel-mediatheque` 2.x are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). The changes of 1.x are in [the `v1.1` changelog](https://github.com/folkloreinc/laravel-mediatheque/blob/v1.1/CHANGELOG.md).

2.0 is released as pre-releases first (`2.0.0-alpha.N`, then beta and RC). Until the release candidate, a pre-release can break the previous one: each pre-release lists its breaking changes first, under **Breaking changes**. [`docs/upgrade.md`](docs/upgrade.md) covers the upgrade from 1.x.

## [Unreleased]

## [2.0.0-alpha.13] - 2026-10-30

### Added

- Document thumbnails: the `ffmpeg` driver runs `document.thumbnails` (Eclair's operation), images of the pages of a PDF, every page or the first `count` (a number, or `'all'`), at `resolution` dpi (150) within `max_width` and `max_height` (1920), in `jpeg` or `png`, rendered by Poppler (`binaries.pdfinfo` and `binaries.pdftoppm`, `MEDIATHEQUE_PDFINFO` and `MEDIATHEQUE_PDFTOPPM`). A default `document` pipeline renders the **first page** of PDFs; 1.x rendered every page: set `count` to `'all'` to keep that. Poppler is optional: without it the rendition fails, before its input is copied, with a message, and the document is ready. `mediatheque:upgrade --config` keeps a 1.x document pipeline that isn't the 1.x default, with every page, and `--config` and `--check` say that the default now renders the first page ([decisions/0025](docs/decisions/0025-document-thumbnails-with-poppler.md), #147).

### Fixed

- `mediatheque:upgrade --config` reads the 1.x file as it is written, not as this machine's environment makes it: a key whose 1.x value is an `env()` call keeps that call, with the default the 1.x file gave it (`env('FFMPEG_BIN', '/usr/local/bin/ffmpeg')`), instead of the value of the local `.env`. The binaries and job settings that 1.x read from the environment keep reading it, and the output lists the variables the rewritten file no longer reads, and the lines whose other code reads this machine (#147).

## [2.0.0-alpha.12] - 2026-10-29

A fix to the upgrade from 1.x (#144): files without a `source` get the default source, so they keep their URL. A database upgraded with 2.0.0-alpha.11 gets it by running `php artisan mediatheque:upgrade` again.

### Fixed

- `mediatheque:upgrade` gives the files of a 1.x database without a `source` the default source (`config('mediatheque.source')`), where 1.x stored them: they had no URL after the upgrade. A database upgraded with 2.0.0-alpha.11 gets it by running `php artisan mediatheque:upgrade` again, and `--check` counts those files (#144).

## [2.0.0-alpha.11] - 2026-10-29

The upgrade from 1.x, in place (#139): `mediatheque:install --upgrade` adds the 2.0 columns to the 1.x tables, `mediatheque:upgrade` converts the data (with `--check`, `--config` and `--cleanup`), a compatibility layer keeps the 1.x API working with deprecation notices, and Rector rules move code to the 2.0 API. Back up the database first: the conversion rewrites some 1.x columns, so going back means restoring the backup ([docs/upgrade.md](docs/upgrade.md)).

### Added

- The upgrade from 1.x, in place ([decisions/0002](docs/decisions/0002-evolve-the-schema-in-place.md), [0024](docs/decisions/0024-upgrade-conversion-rules.md), #139):
  - `mediatheque:install --upgrade` publishes the `upgrade_mediatheque_tables_from_1x` migration instead of `create_mediatheque_tables`: it adds the 2.0 columns to the 1.x tables, with any `table_prefix`, and renames nothing. `mediatheque:install` without `--upgrade` refuses a database that has the 1.x tables.
  - `mediatheque:upgrade --check` lists, without changing anything, the orphan rows the upgrade removes, the morph types it doesn't recognize, the runs and 1.x jobs it can't convert, the handles that break the 2.0 rule, the files attached to other models or to several media, and the media whose type has no 2.0 renditions (1.x fonts).
  - `mediatheque:upgrade` converts the data in resumable batches: files get their media and their handle split into handle and index, media a `uid`, a status and their metadata as JSON with the canonical keys, runs the 2.0 statuses and a JSON definition, read from the 1.x serialized ones without creating objects. It then removes orphan rows and adds the foreign keys. `--media-morph` and `--file-morph` name other morph types 1.x rows use; it refuses to start while metadata has a morph type it doesn't recognize, unless `--accept-unknown-morphs`.
  - `mediatheque:upgrade --config` rewrites a 1.x `config/mediatheque.php` in the 2.0 shape, with only the application's overrides, and prints the disks that stand for its sources. `--cleanup` drops the 1.x `metadatas` table, unless it holds metadata that no converted media or file has.
  - The compatibility layer of the 1.x API, deprecated until 3.0: on the media model, `getFile()` (with 1.x handles such as `thumbnails.0`), `hasFile()`, `getFiles()`, `getOriginalFile()`, `getThumbnails()`, the `files` property, `getMetadatas()`, `getMetadata()`, `getUrl()` (the original's URL, as in 1.x), `setOriginalFile()` and `runPipeline()`, and eager loading of the `files` relation as `mediaFiles`; on the file model, `getHandle()` and `getUrl()`; the 1.x `Support\Traits\HasMedias` trait; the 1.x type factory (`Contracts\Type\Factory`); and container bindings of the media and file contracts. Each use is reported once on the `deprecations` channel.
  - Rector rules, in `rector.php` at the package's root, that rewrite 1.x code to the 2.0 API: the 1.x trait, `getFile()` and `hasFile()` with handles written in the code (1.x handles split), `getMetadatas()->get()->getValue()` and `getMetadata()->getValue()` with names written in the code, `runPipeline()`, `getOriginalFile()`, a media's `getUrl()` (to `original()?->url()`) and a file's.
  - A 1.x `config/mediatheque.php` is read in the 2.0 shape, with a deprecation notice on the `deprecations` channel: values equal to the 1.x defaults give way to the 2.0 defaults, and each 1.x source is a Laravel disk of the same name unless the application defines one. A disk of the same name that doesn't store files as the source did (Laravel's `public` against the 2017–2018 1.x default) is logged as a warning, and listed by `--check` and `--config`.

## [2.0.0-alpha.10] - 2026-10-28

Images get a ThumbHash placeholder at probe, and so do video posters (#131). HEIC, HEIF and AVIF images get a JPEG web version at ingest, which the media plays instead of its original (#132). `MediaResource` and `FileResource` give the canonical JSON shape of a media, with `Media::failure()` (#133). A CDN in front of public files is the disk's `url` (#128). There is no new migration. If your published config sets `types.image.pipeline` to `null`, remove that line to get the web version of HEIC images. Images probed before keep no placeholder until they are probed again.

### Breaking changes

- A skipped main rendition makes the media `ready`, played from its original, instead of `failed`. A media whose main rendition's condition (`when`) doesn't match its probe is `ready` once probed, and a pipeline none of whose kept renditions match the probe doesn't run. A main rendition that failed or was canceled still fails the media ([decisions/0014](docs/decisions/0014-run-outcomes-and-model-contracts.md), #132).
- The `image` type runs the new `image` pipeline. An application whose published config sets `types.image.pipeline` to `null`, as the previous default did, keeps no pipeline for images: remove that line to get the `web` version of HEIC images (#132).
- The `Media` contract has `failure()`: a class that implements it implements it too (#133).

### Added

- Images get a ThumbHash placeholder, in the `thumbhash` metadata key, which the `media` resolver passes to `laravel-image`. The local prober computes it for every image but SVG, and the `ffmpeg` driver for the image files it writes, such as a video's poster: the first frame, upright, downsized to fit 100×100, with Imagick, or GD without it. It doesn't need `laravel-image`. An image it can't compute one for has no `thumbhash`, and is probed all the same. Images probed before keep none until they are probed again ([decisions/0013](docs/decisions/0013-media-resolver-for-laravel-image.md), #131).
- HEIC, HEIF and AVIF images get a web version at ingest: the default `image` pipeline's `web` rendition, a JPEG of the same size, upright, with the color profile and no other metadata. The media plays it: `url()`, and `med_…` in the `media` resolver, which gives it the media's `transform` and placeholder. The original is kept, available by handle (`file('original')`, `med_…/original`). The media is `ready` once its JPEG is written. Other images run nothing and are `ready` once probed, as before ([decisions/0009](docs/decisions/0009-package-boundaries.md), #132).
- The `ffmpeg` driver's `image.convert` operation, with Imagick: `format` (`jpeg`, `webp` or `png`) and `quality` (85). A JPEG of a transparent image is flattened over white (#132).
- `MediaResource` and `FileResource`, the canonical JSON shape of a media and its files. They have the meaning of Panneau 5's `MediaInfo` in snake_case, plus `error`, `processing`, `transform`, the original's `mime` and `size`, and `files` by handle, the original first, a group as a list. The fixtures in `tests/fixtures/json/` are the shapes an adapter tests against. There is no `legacy` shape: `laravel-folklore` keeps its own ([decisions/0010](docs/decisions/0010-json-resource.md), #133).
- `$media->failure()`, on the contract, the package's model and the fake's media: why a `failed` media failed, the error of the first rendition of its latest unfinished run that failed for its own reason, or its probe's; null otherwise (#133).

## [2.0.0-alpha.9] - 2026-10-28

The progress of runs is null until something measures it, weighted by each input's duration, and read by `$media->processing()` and the new `RenditionProgressed` event (#117). A media's visibility can change, and its stored files follow (#119). Ingest removes the location from images, without re-encoding them (#120). `mediatheque:prune` force-deletes media deleted long ago and deletes old runs, and force deletion deletes the stored files (#121). Run `php artisan mediatheque:install`, then `php artisan migrate`: it publishes the new `make_mediatheque_progress_nullable` migration only. Then schedule `mediatheque:prune` daily.

### Breaking changes

- `PipelineJob::progress()` and `PipelineRun::progress()` return `?float`: null while nothing measured the progress, instead of 0. Code that reads them handles null, and a class that implements the contracts returns it. `PipelineRunResource` and `PipelineJobResource` give `progress: null` rather than `0` ([decisions/0023](docs/decisions/0023-progress-of-runs.md), #117).
- The `progress` columns of `pipelines` and `pipelines_jobs` are nullable, `null` by default: run `php artisan mediatheque:install`, then `php artisan migrate`. The new `make_mediatheque_progress_nullable` migration turns the 0 of renditions that didn't complete, and of runs that haven't ended, into null (#117).
- A run's progress is weighted: a transcode weighs the input's duration in seconds and any other rendition a second, an ended rendition is done, and one that nothing measured counts as not started. It was the plain mean of its renditions (#117).
- The `Media` contract has `processing()`: a class that implements it implements it too (#117).
- The `Mediatheque` contract has `setVisibility()`: a class that implements it implements it too (#119).

### Added

- `$media->processing()`, on the contract, the package's model and the fake's media: the media's latest run in progress as a readonly `Pipelines\Processing` (the media's `status`, the run's uid, `pipeline` and `progress`, and the `rendition` that is running), or null when nothing runs. For editors' interfaces, such as Panneau 5's media field: "transcoding: 42 %" (#117).
- The `RenditionProgressed` event: the job, the rendition's progress and the run's. The recorder dispatches it each time a rendition's progress passes a multiple of the new `events.progress_step` setting (0.05 by default), never for a call that changes nothing or for a status change. The package doesn't broadcast it: `docs/architecture.md` shows how an application does, on its own channels (#117).
- `Mediatheque::fake()->progressRendition($media, 'h264', 0.42)`: reports a rendition's progress through the fake's recorder, as a driver would (#117).
- `Mediatheque::setVisibility($media, $visibility)` and `$media->setVisibility()` change a media's visibility; false when it has it already. The media has it at once, so `url()` gives temporary URLs of a media made private right away. The new `ApplyVisibility` job then applies the media's visibility to its stored files, the original and the renditions; it reads the visibility when it runs, skips a file its disk no longer has, and fails, to be retried, when a file's visibility can't be set. The fake applies it to its fake disks at once ([decisions/0007](docs/decisions/0007-visibility-and-urls.md), #119).
- `MediaVisibilityChanged` is dispatched, with the previous and the new visibility (its new `visibility` property). It existed, but nothing dispatched it (#119).
- `mediatheque:prune`, which the application schedules daily: it force-deletes media soft-deleted more than `prune.after_days` ago (30 by default), and deletes runs that ended more than `prune.runs_after_days` ago (7 by default) with their renditions, keeping each media's last run of each pipeline. `--dry-run` lists what it would delete. It works in batches; null leaves media or runs. `mediatheque:install` says how to schedule it ([decisions/0017](docs/decisions/0017-monitoring-dashboard.md), #121).

### Changed

- Force-deleting a media deletes its stored files, the original and the renditions, in a queued job (`DeleteStoredFiles`) once committed, and its pipeline runs, which had no foreign key to remove them. Its file rows are deleted too, for tables upgraded from 1.x that have no foreign key yet (#121).
- The recorder stores the run's progress in `pipelines.progress` with each call, for queries; it was 0 until the run ended (#117).
- When the recorder records the files of a run that started before the media's visibility changed, it queues `ApplyVisibility`, so they get the media's current visibility (#119).
- The monitoring dashboard shows progress as a bar: indeterminate while nothing measured a running run or rendition, a dash for an unknown progress otherwise. `mediatheque:status` shows a dash too (#117).

### Fixed

- Ingest removes the location from images, as the `Ingestor` contract says: the EXIF GPS entries and the XMP `GPS…` properties of JPEG, PNG, WebP, TIFF, HEIF and AVIF images are blanked before the original is stored, also when an original is replaced. The image is not re-encoded: it keeps its length, its pixels, its orientation and its other metadata, and its checksum is the stripped file's. An image on a disk is downloaded to be read whole. The new `ingest.images.strip_location` setting, true by default, keeps the location when it is false ([decisions/0006](docs/decisions/0006-ingest-and-uploads.md), #120).

## [2.0.0-alpha.8] - 2026-10-26

The migrations and the test suite run on PostgreSQL, with `jsonb` columns, and CI runs the tests on PostgreSQL 17 (#103). A media stores the edits of its image, which the `media` resolver passes to `laravel-image` 2.0.0-alpha.2, and images their EXIF orientation (#107). Temporary URLs can download their file under a name (#102), and path placeholders read a model's accessors (#64). Run `php artisan mediatheque:install`, then `php artisan migrate`: it publishes the two new migrations only, which add the `transform` column and turn the JSON columns into `jsonb` on PostgreSQL. Update `folklore/laravel-image` to 2.0.0-alpha.2 or later.

### Breaking changes

- The `media` resolver needs `laravel-image` 2.0.0-alpha.2 or later, which reads the flips of a transform and adds the edits to the version of URLs: this package conflicts with 2.0.0-alpha.1 (#107).
- The `Media` contract has `transform()`: a class that implements it implements it too (#107).
- `UrlGenerator::temporaryUrl()`, `File::temporaryUrl()` and `Media::temporaryUrl()` take a third parameter, `bool|string $download = false`: a class that implements them, or a model that overrides them, adds it too (#102).
- The `File` contract has `name()`, the file's name as stored: a class that implements it implements it too (#102).

### Added

- A media's `transform`: the non-destructive edits of its image, in the shape of Panneau 5's `MediaTransform` (`crop`, `rotate`, `flipX`, `flipY`, `focalPoint`), stored outside `metadata` by the new `add_transform_to_mediatheque_medias_table` migration, and read by `$media->transform()`, on the package's model and the fake's media. Its cast checks the shape when it is written and throws `InvalidTransform` otherwise. `replaceOriginal()` keeps it ([decisions/0022](docs/decisions/0022-image-transform-and-orientation.md), #107).
- The `media` resolver of `laravel-image` passes the file's `orientation` to the `ImageSource`, and the media's `transform`, focal point included, for its image: the original of an `image` media, not a video's poster or thumbnails. The source's version stays the file's: `laravel-image` adds the edits to the version of URLs (#107).
- The canonical metadata key `orientation`: an image's EXIF orientation, from 1 to 8, when it declares one, on the media and its original. Originals are not turned upright; `width` and `height` stay the upright size (#107).
- The `use_jsonb_in_mediatheque_tables_on_postgresql` migration: on PostgreSQL, the JSON columns of the media, files, runs and renditions become `jsonb`, which can be compared and indexed. A column whose type is no longer `json` is left as it is. The other databases keep `json` (#103).
- A path placeholder `{media.<attribute>}` reads the media model's accessor when no column has that name, so paths can be grouped by a tenant's public id (`{media.tenant_uid}`) rather than by its sequential key. An accessor can read a relation whose foreign key `attributes` sets at ingest. A column keeps its raw value, even with a cast or an accessor of the same name, and a model that prevents accessing missing attributes gets `InvalidPath` for an attribute that has neither (#64).
- Temporary URLs that download their file rather than show it, on the `UrlGenerator`, files, media and the fake: `download: 'clip.mp4'` names the file, and `download: true` takes the file's name on a file, and on a media its name with the extension of the file it serves (`episode-1.mp4`), or the file's name when the media has none. On S3 and S3-compatible disks, such as DigitalOcean Spaces, the presigned URL asks for `Content-Disposition: attachment` with the name in UTF-8 (`filename*`) and in ASCII (`filename`), as RFC 6266 describes. A local disk ignores the option: Laravel's signed route has no equivalent. A name that is not a file name throws `InvalidArgumentException`. The fake's URLs carry the name as `download` (#102).

### Changed

- The monitoring dashboard is built with `@panneau/ui` 5.0.0-alpha.5, the current alpha, instead of alpha.3. It needed no change (#106).
- The docs and `HasMedias`' docblock give `json` as the handle that JSON references sync under, for every JSON column of a model, as `laravel-json-data` and `laravel-folklore` 2 do: [decisions/0021](docs/decisions/0021-json-references-handle.md) amends 0020, which said `json:{column}` (#104).

## [2.0.0-alpha.7] - 2026-10-23

The migrations run on MySQL, and CI runs the tests on MySQL 8.4 (#98). There is no new migration: the fix is in the migration that alpha.6 added, for the projects that haven't run it yet. A project whose `migrate` failed on MySQL with alpha.6 follows the first item below.

### Fixed

- `migrate` works on MySQL. The `create_mediatheque_medias_pivot_table` migration names its unique index `{prefix}medias_pivot_usage_unique` and its morph index `{prefix}medias_pivot_morphable_index`: the generated name was longer than the 64 characters MySQL allows (#98). If `migrate` failed on MySQL with alpha.6, the table was created without its unique index: drop the empty `medias_pivot` table, delete the published `…_create_mediatheque_medias_pivot_table.php`, then run `php artisan mediatheque:install` and `php artisan migrate`.
- The package's migration files start with a timestamp, so a migrator that loads them from the package, as a package's tests do with `InstallCommand::migrations()`, runs them in order: the pivot's foreign key needs the media table first. `mediatheque:install` publishes them under the same names as before (#98).
- `Monitoring::durations()` is sorted by pipeline, rendition and transcoder, instead of the order the database returned (#98).

## [2.0.0-alpha.6] - 2026-10-23

Application models attach media by handle, and a media in use can't be deleted unless the deletion is forced ([decisions/0020](docs/decisions/0020-attachments-by-handle-and-usages.md), #92). Run `php artisan mediatheque:install`, then `php artisan migrate`: it publishes the new migration only, which creates `medias_pivot`.

### Breaking changes

- The `Media` contract has `usages()` and `isUsed()`: a class that implements it implements them too (#92).
- `$media->delete()` throws `MediaInUse` when a model uses the media, and reads `medias_pivot`: publish and run the new migration before deleting media (#92).

### Added

- The `create_mediatheque_medias_pivot_table` migration: `medias_pivot` with the table prefix, a foreign key to the media and a unique index on the model, handle and media. A project upgraded from 1.x keeps its table (#92).
- `Folklore\Mediatheque\Concerns\HasMedias` for application models ([decisions/0020](docs/decisions/0020-attachments-by-handle-and-usages.md), #92):
  - `medias()`, the morph-to-many relation, with `handle` and `order` on its pivot;
  - `medias($handle)` and `media($handle)`, read from the loaded relation;
  - `attachMedia($media, $handle)`, after the media already under the handle;
  - `syncMedias([$handle => $medias])`, which replaces the media of the handles it names only, so `laravel-folklore` syncs JSON references under `json:{column}` without detaching media attached otherwise;
  - `detachMedia($media, $handle)`;
  - a deleted model no longer uses its media; a soft-deleted one keeps them.
- The usage graph: `$media->usages()`, a `MediaUsage` per model and handle, and `$media->isUsed()`, on the package's `Media` model and the fake's media (#92).
- `MediaInUse`, thrown by `delete()` on a media in use. `forceDelete()` deletes it and removes its usages (#92).

## [2.0.0-alpha.5] - 2026-10-22

A media's file can be replaced while the media keeps its uid, so `media://` references keep working ([decisions/0019](docs/decisions/0019-replace-original.md), #87). There is no new migration.

### Breaking changes

- The `Mediatheque` contract has `replaceOriginal()`: a class that implements it implements it too (#87).
- `OriginalReplaced` carries `previousChecksum` and `checksum` instead of the previous `File`, whose row is removed when the original is replaced. It was not dispatched before (#87).

### Added

- `Mediatheque::replaceOriginal($media, $source, $options, allowTypeChange: false)`, on the manager and the fake, and `$media->replaceOriginal($source)` on the package's `Media` model and the fake's media ([decisions/0019](docs/decisions/0019-replace-original.md), #87):
  - the new file is validated as ingest validates it, and a file ingest would refuse changes nothing;
  - a file of another type is refused with `RejectionReason::TypeChanged`, unless `allowTypeChange` is set;
  - the media keeps its uid, visibility, data, attributes and usages, and its name unless the options give one;
  - the renditions are removed and their files deleted in a queued job, runs in progress are canceled, and the media is probed, then its pipeline runs again;
  - `OriginalReplaced` is dispatched with the previous and the new checksum, so `laravel-image` URLs change;
  - replacing an original with the same file again changes nothing.

### Changed

- `DeleteStoredFiles` keeps a path that a file records again by the time it runs.
- A probe job that reads an original replaced in the meantime records nothing.

## [2.0.0-alpha.4] - 2026-10-22

What a monitoring view needs is recorded ([decisions/0017](docs/decisions/0017-monitoring-dashboard.md), #71), and uploads leave the package: it ingests files, and applications take uploads, through Panneau 5's direct uploads or their own routes ([decisions/0018](docs/decisions/0018-ingest-only.md)). Run `php artisan mediatheque:install`, then `php artisan migrate`: it publishes the new migrations only, and the last one drops the `uploads` table.

### Breaking changes

- The `Mediatheque` contract has `retry()`, `cancel()`, `refresh()` and `probeAgain()`: a class that implements it implements them too (#73).
- The `PipelineRun` and `PipelineJob` contracts have timestamp methods: a class that implements them implements them too (#77).
- Uploads are removed ([decisions/0018](docs/decisions/0018-ingest-only.md)). Upload through Panneau 5's direct uploads, or your own routes, then ingest the file with `Mediatheque::ingest(Source::disk($disk, $path, $filename))`. Removed:
  - `Route::mediatheque()` and its upload routes;
  - `Mediatheque::uploadsUsing()`: pass the media's options to `ingest()`;
  - `Folklore\Mediatheque\Models\Upload`, `Enums\UploadStatus` and `Folklore\Mediatheque\Uploads\*`;
  - the `uploads` config and the `models.upload` key;
  - the `uploads` table, dropped by the new `drop_mediatheque_uploads_table` migration.

### Added

- `queued_at` on pipeline jobs: when the rendition can run, that is when its run is created, or when the rendition it reads from completes.
- `IngestRejected`, dispatched when ingest refuses a file, with its reason, message and file name. `Mediatheque::fake()` dispatches it too.
- Indexes on `created_at` and `ended_at` of `pipelines` and `pipelines_jobs`.
- Actions on runs in the `Mediatheque` contract (#73), on the manager and the fake:
  - `retry($run)`: a new run of the failed and canceled renditions of a run that ended, produced again even if a file exists. It returns null when there is nothing to retry, or while the media has a run of the same pipeline in progress. Renditions that a later run of the pipeline completed are not retried, so retrying twice creates one run;
  - `cancel($run)` and `refresh($run)`: through the transcoder's capabilities. They return false when the run has ended or the transcoder can't;
  - `probeAgain($media)`: a media whose probe failed goes back to `pending`, and its probe is queued with the pipeline ingest chose.
- The `Monitoring` read model (#75):
  - `overview()`, `runs()` with filters and cursor pagination, `failures()` grouped by code, `durations()` with medians, p95 and queue waits, and `throughput()` per hour;
  - `Mediatheque::monitoringScope()` narrows what it reads, for a tenant;
  - new `monitoring.stuck_after` and `monitoring.max_rows` config keys.
- `php artisan mediatheque:status`: media by status, runs in progress and stuck, what ended in the last hours, and the latest failures (#75).
- `PipelineRunResource` and `PipelineJobResource`, the JSON shapes of runs and their renditions, with fixtures in `tests/fixtures/json/` (#77).
- The monitoring dashboard (#81):
  - `Route::mediathequeDashboard()` serves a precompiled React SPA on `@panneau/ui`, with an overview, runs and their details, and failures;
  - from it, runs can be retried, canceled and refreshed, and media probed again;
  - the `viewMediathequeDashboard` and `operateMediathequeDashboard` gates are open in `local` until the application defines them;
  - `mediatheque:install --dashboard` publishes a provider that defines them.
- `createdAt()`, `startedAt()` and `endedAt()` on the `PipelineRun` contract, and `queuedAt()`, `startedAt()` and `endedAt()` on the `PipelineJob` contract. The fake's recorder sets them as the Eloquent recorder does (#77).

### Changed

- Every probe is a run of the `probe` pipeline, not only pending ones: a probe's duration, attempts and error are recorded, and `PipelineStarted`, `RenditionCompleted` or `RenditionFailed`, and `PipelineCompleted` or `PipelineFailed` are dispatched for it, before `MediaProbed` or `MediaFailed`. A listener that only cares about renditions skips runs named `probe`.
- A probe sent to a driver that answers later is `running`, not `queued`. A retried probe job reuses its run, and doesn't send a probe that is pending with its driver again.

### Fixed

- A pending probe that completed through the recorder made the media `ready` without probing it, and its pipeline never started.

## [2.0.0-alpha.3] - 2026-10-21

Direct uploads with Uppy 6.

### Added

- Direct uploads for Uppy 6's `AwsS3` plugin (`@uppy/aws-s3` 6.1 or later), whose `signRequest` sends every S3 request from the browser: `POST uploads/sign` takes Uppy's request (`method`, `key`, `uploadId`, `partNumber`) and returns `{url, key}`. A `CreateMultipartUpload` or `PutObject` creates an upload for the user with its own key (`{uploads.path}/{upl_…}`); any other request is signed only for the key of an open upload of the same user, and for the first multipart upload signed for it. `POST uploads/{upload}/complete` without `parts` ingests a file the client already completed on the storage (`409` until it is there), and takes the media's `filename`. With Uppy 6, the bucket's CORS rules must also allow `POST`, `GET`, `DELETE` and the `Content-Type` header ([decisions/0016](docs/decisions/0016-uploads-signed-for-uppy-6.md), #65).

### Changed

- The original of a media ingested from the same disk, as uploads are, gets the type detected from its content on the storage, rather than the type it was uploaded with ([decisions/0016](docs/decisions/0016-uploads-signed-for-uppy-6.md), #65).

### Fixed

- The upload routes work under a prefix that has its own parameters, such as `workspaces/{workspace}`: the actions received the prefix's parameter as the upload's id, and answered `404` (#65).

## [2.0.0-alpha.2] - 2026-10-16

Image URLs for media, with `laravel-image` 2 (v2.0.0-alpha.1 or later).

### Added

- The `media` resolver of `laravel-image` 2, registered when `laravel-image` is installed, even when another provider built its resolvers first (#60): `med_…`, `med_…/{handle}` and `med_…/{handle}/{index}` resolve to the media's image files, with the first 8 hex characters of the file's checksum as version and its source as disk; deleted media don't resolve; private media are private sources ([decisions/0013](docs/decisions/0013-media-resolver-for-laravel-image.md), #56).

## [2.0.0-alpha.1] - 2026-10-16

The first alpha of 2.0, for new projects: upload and play a video. The [README](Readme.md) says how to install and use it. The upgrade from 1.x comes in the beta.

### Breaking changes

- 2.x requires PHP 8.3+ and Laravel 12+ ([decisions/0001](docs/decisions/0001-supported-versions.md), #28).
- The 1.x code is removed from `v2.x`; 2.0 is rewritten behind the 1.x tables ([decisions/0002](docs/decisions/0002-evolve-the-schema-in-place.md), #29). Nothing of the 1.x API is available until the compatibility layer lands, in the beta.

### Added

- The 2.0 contracts, with their tests (#31): `Mediatheque`, `Ingestor` and ingest `Source`s, `Prober` and `ProbeOutcome`, `Transcoder`, `Recorder`, `Capabilities`, `UrlGenerator`, `UrlSigner`, and the `Media`, `File`, `PipelineRun` and `PipelineJob` model contracts.
- Prefixed ULIDs (`Support\Uid`) and `MediaReference`, which parses `media://med_…` and the 1.x `medias://{id}` (#31).
- Rendition and pipeline definitions, validated when they are read: handle rule, `when` conditions on probe kinds, `from` without cycles, runs closed under `from` (#31).
- Enums (`MediaStatus`, `Visibility`, `PipelineStatus`, `JobStatus`, `ProbeKind`, `TranscoderErrorCode`, `ProgressReporting`, `RejectionReason`), the canonical metadata keys, and the events of the architecture plus `MediaFailed` (#31).
- `Mediatheque::fake()`, with in-memory media, probe fixtures, placeholder renditions and assertions (#31). There is no implementation behind the facade yet: outside the fake, it doesn't resolve.
- The config is merged deeply with the package's defaults, so a published config only needs its overrides; lists and a pipeline's `renditions` replace the defaults, and `false` removes a type or a pipeline. Types, pipelines and sources are validated when they are read, with the key that is wrong; sources are Laravel disk names, and each type has a maximum size (#40).
- The schema of a new install: `medias`, `files`, `pipelines` and `pipelines_jobs`, with their 1.x names, foreign keys and 64-bit sizes ([decisions/0015](docs/decisions/0015-new-install-schema-attributes-and-uploads.md), #42).
- `php artisan mediatheque:install` publishes the config and the migrations the application doesn't have yet, with fresh timestamps. The package never loads its migrations: the application owns its schema and can add its own columns (#22, #42).
- Eloquent models `Media`, `File`, `PipelineRun` and `PipelineJob`, which implement the model contracts. Set your own classes in `mediatheque.models`. Their relations are `mediaFiles()`, `pipelineRuns()`, `pipelineJobs()`, `pipelineRun()`, `pipelinable()` and `File::media()` ([decisions/0014](docs/decisions/0014-run-outcomes-and-model-contracts.md), #42).
- `Media::thumbnail()` returns the poster first, then the first thumbnail (#42).
- The path formatter: `file_path_format` builds the path of originals and names the `ffmpeg` driver's files, and `run_path_format` the prefix each run writes under. Placeholders: `{media.<attribute>}` (an application's `{media.tenant_id}`), `{type}`, `{handle}`, `{index}`, `{extension}`, `{run}`, `{date(Y/m)}`. A missing attribute, or a value that would leave its directory, throws (#44).
- `url()` and `temporaryUrl()` on media and files, through the package's `UrlGenerator`: a public file gets its disk's URL; a private file only gets temporary URLs, signed by its disk (S3, or a local disk with `'serve' => true`), valid for `urls.temporary_ttl` seconds by default. A private file on a disk that can't sign URLs has no URL (#46).
- `Mediatheque::resolve()` and `resolveMany()` find media by uid, `media://` and `medias://` references, with their files, in one query per form. Deleted media don't resolve. The facade works outside the fake (#46).
- The probe runs in a queued job (`Jobs\ProbeMedia`) with the local prober: ffprobe for video and audio (read through a temporary URL on disks that sign them), Imagick or `getimagesize()` for images. It reports the canonical metadata keys, with the displayed width and height, computes the original's checksum when ingest didn't, and moves the media to `processing` (`MediaProbed`), to `ready` when its type has no main rendition (`MediaReady`), or to `failed` (`MediaFailed`). A pending outcome is recorded as a run of the `probe` pipeline. New config: `binaries`, `probe.timeout` and `jobs` (connection, queue, tries, timeout) (#48).
- `Mediatheque::ingest()` and the `Ingestor` contract, from an uploaded file, a local path, a stream or a file on a disk (`Source::disk()`: copied on the server when it is the same disk, its checksum computed by the probe). The type comes from the mime sniffed from the content, never from the client's file name, extension or `Content-Type`; the stored extension is derived from that mime; a file of no type is rejected, and so are mimes a server could execute or a browser render as an active document. Each type's `max_size` is checked. SVG files are sanitized (`rhukster/dom-sanitizer`) and invalid ones rejected. A rejected or failed ingest leaves neither a file nor a row; `MediaCreated` and the probe follow the commit. URL imports come in a later alpha (#50).
- `attributes` on `Mediatheque::ingest()` and `IngestOptions`: the application's columns, such as a tenant id, set before the path is built ([decisions/0015](docs/decisions/0015-new-install-schema-attributes-and-uploads.md), #50).
- The Eloquent recorder, bound to the `Recorder` contract: each call locks its run's row and only moves statuses forward, so webhooks and retried jobs reconcile in any order; failures, skips and cancellations propagate to the renditions that read from them; a run ends `failed`, `canceled` or `completed`, and the media follows its main rendition. A `ready` media stays `ready` when a rerun fails to produce its main rendition again ([decisions/0014](docs/decisions/0014-run-outcomes-and-model-contracts.md)). Files a rerun replaces are deleted in a queued job. It completes pending probes (#52).
- `Mediatheque::runPipeline()` and the pipeline runner: only the renditions that are missing, failed or changed run, closed under `from`, under the run's own path prefix, with the media's visibility. The pipeline starts after `MediaProbed`: the one ingest chose, or the type's (#52).
- The `ffmpeg` transcoder, on `symfony/process`: `video.transcode` (H.264 MP4 with `+faststart`, or VP9 WebM, upright and fitted in `max_width`/`max_height`), `video.poster`, `video.thumbnails`, `audio.transcode` (MP3 or AAC) and `audio.waveform` (a PNG drawn by ffmpeg). Each rendition is a queued job (`Jobs\RunRendition`) on the rendition's `queue` or `jobs.queue`; renditions that read from another one are dispatched when it completes; progress is reported; temporary files are always removed (#52).
- `RecorderContractTests` covers the rules of a rerun ([decisions/0014](docs/decisions/0014-run-outcomes-and-model-contracts.md)), and the fake's recorder follows them (#52).
- Presigned multipart uploads to an S3-compatible disk, for Uppy's `AwsS3` plugin: `Route::mediatheque()` registers routes to create an upload, sign its parts, list them, complete and abort it, inside the application's own middleware. A fresh install still exposes no route. Each step is authorized with the `create` ability of the media policy (denied without a policy); an upload belongs to the user who created it, and guests can't upload. Completing an upload ingests the file from the disk with the options of `Mediatheque::uploadsUsing()` (the application's attributes, such as a tenant id, chosen on the server); a rejected file is deleted. Errors are `application/problem+json`. New: the `uploads` table (published by `mediatheque:install`), the `Upload` model, and the `uploads` config (`disk`, `path`, `expires_after`, `url_ttl`) ([decisions/0015](docs/decisions/0015-new-install-schema-attributes-and-uploads.md), #54).
- Releases are tagged by CI, from a commit of `v2.x` whose lint and test jobs passed, when `CHANGELOG.md` gets a dated section for a new version (#30).

[Unreleased]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.13...v2.x
[2.0.0-alpha.13]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.12...v2.0.0-alpha.13
[2.0.0-alpha.12]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.11...v2.0.0-alpha.12
[2.0.0-alpha.11]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.10...v2.0.0-alpha.11
[2.0.0-alpha.10]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.9...v2.0.0-alpha.10
[2.0.0-alpha.9]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.8...v2.0.0-alpha.9
[2.0.0-alpha.8]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.7...v2.0.0-alpha.8
[2.0.0-alpha.7]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.6...v2.0.0-alpha.7
[2.0.0-alpha.6]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.5...v2.0.0-alpha.6
[2.0.0-alpha.5]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.4...v2.0.0-alpha.5
[2.0.0-alpha.4]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.3...v2.0.0-alpha.4
[2.0.0-alpha.3]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.2...v2.0.0-alpha.3
[2.0.0-alpha.2]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.1...v2.0.0-alpha.2
[2.0.0-alpha.1]: https://github.com/folkloreinc/laravel-mediatheque/compare/v1.1.3...v2.0.0-alpha.1
