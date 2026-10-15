# Upgrading from 1.x

> **Draft.** This guide is filled as 2.0 lands. The steps and the mapping below are the plan ([decisions/0002](decisions/0002-evolve-the-schema-in-place.md)); the commands they mention don't exist yet.

2.0 keeps the 1.x tables and converts them in place. A compatibility layer keeps the 1.x API working with deprecation notices until 3.0, so a project can upgrade first and clean up its code afterwards.

## Before you start

- The project runs **PHP 8.3+ and Laravel 12+** ([decisions/0001](decisions/0001-supported-versions.md)). Upgrade Laravel first if needed.
- The project requires a tagged 1.x release (`^1.1`), not `v1.1.x-dev`, and is on the latest one.
- Back up the database and know where the media files are stored.
- If the project uses `laravel-folklore`, check which of its versions supports mediatheque 2.0.

## Steps

1. **Require the new version:** `composer require folklore/laravel-mediatheque:^2.0`.
2. **Check the database:** `php artisan mediatheque:upgrade --check`. It lists:
   - orphan rows (pipelines, metadata, pivots of deleted media) that block the foreign keys;
   - serialized pipeline definitions it can't convert (custom classes);
   - handles that don't follow the 2.0 rule;
   - files attached to models other than media through `files_pivot`.
3. **Publish and run the migration:** `php artisan mediatheque:install --upgrade`, then `php artisan migrate`. The migration adds columns; it renames no table.
4. **Convert the data:** `php artisan mediatheque:upgrade`. It backfills `uid`, copies metadata into JSON, converts pipelines to the new statuses and JSON definitions, splits grouped handles into handle and index, and adds the foreign keys. It can run in batches and be resumed.
5. **Update the config.** The 1.x file keeps working, with deprecation notices. `php artisan mediatheque:upgrade --config` rewrites it with only the project's overrides, in the 2.0 shape.
6. **Run the Rector rules:** `vendor/bin/rector process --config vendor/folklore/laravel-mediatheque/rector.php`.
7. **Routes.** If the project used the package routes, register them with `Route::mediatheque()` inside its middleware, and write a policy for the media model ([decisions/0008](decisions/0008-secure-http.md)).
8. **Run the project's tests**, upload a file of each type, and check pages that show media.
9. **Fix the deprecations** at your own pace. They are logged on the `deprecations` channel.
10. **Later:** `php artisan mediatheque:upgrade --cleanup` drops the unused EAV `metadatas` table once you have checked the converted data.

## What changes

### Schema

| 1.x | 2.0 |
|---|---|
| `medias.id` exposed | `medias.uid` (`med_…`) exposed; `id` stays internal |
| — | `medias.status`, `visibility`, `metadata`, `derived_from_id`, `deleted_at` |
| `files_pivot` links files to media | `files.media_id`; `files_pivot` is no longer used |
| `files.mime` 50 characters, `size` 32-bit | 255 characters, 64-bit |
| `files.handle` `thumbnails.0` | `handle` `thumbnails`, `index` 0, `role` |
| `metadatas` EAV table | `metadata` JSON on media and files |
| `pipelines.definition` serialized | JSON snapshot; `status`, `attempts`, `error` |
| `started`, `ended`, `failed` booleans | `status` enum |
| No foreign keys | Foreign keys on files, pipeline jobs and pivots |

Existing media become `public` and `ready` (or `failed` if their last pipeline failed). Their files and URLs don't move.

### API

| 1.x | 2.0 | Compatibility layer |
|---|---|---|
| `Folklore\Mediatheque\Support\Traits\HasMedias` | `Folklore\Mediatheque\Concerns\HasMedias` | Alias |
| `$media->getFile('h264')`, `hasFile()`, `getFiles()` | `$media->file('h264')`, `files()` | Deprecated proxies |
| `$media->getFile('thumbnails.0')` | `$media->file('thumbnails', 0)` | 1.x handles still resolve |
| `$media->setOriginalFile($path)` | `Mediatheque::ingest($path)`, `$media->replaceOriginal($source)` | Deprecated proxy |
| `$media->getMetadatas()->get('width')->getValue()` | `$media->metadata('width')` | Deprecated proxy |
| `$media->runPipeline($name)` | `Mediatheque::runPipeline($media, $name)` | Deprecated proxy |
| `$media->getUrl()` | `$media->url()` | Deprecated proxy |
| `app(TypeFactory::class)->typeFromPath($path)->newModel()` | `Mediatheque::ingest($path)` | Deprecated proxy |
| `medias://{id}` references | `media://med_…` | `medias://` still resolves |
| Contracts bound with `app()->bind()` | Models set in `config('mediatheque.models')` | 1.x bindings still honored |
| `Support\PipelineJob` subclasses | Custom operations of the `ffmpeg` transcoder | Manual (see below) |
| `Route::mediatheque()` loaded by default | Registered by the application, behind a policy | — |
| `media()` helper | `Mediatheque::ingest()` | Removed |

### Custom pipeline jobs

A 1.x `PipelineJob` subclass becomes a custom operation:

1. Implement the operation contract of the `ffmpeg` driver (input file, options, output files).
2. Register it under a name (`app.bumper`) in `transcoders.ffmpeg.operations`.
3. Reference it from a rendition: `'bumper' => ['op' => 'app.bumper', …]`.

Custom operations run only on the `ffmpeg` driver.

### JSON

The package's `MediaResource` uses `uid` as `id` and returns `files` as an object keyed by handle ([decisions/0010](decisions/0010-json-resource.md)). Front ends that expect the 1.x `laravel-folklore` shape use the `legacy` option until they move.
