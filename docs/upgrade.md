# Upgrading from 1.x

> **Draft.** This guide is filled as 2.0 lands. The migration, `mediatheque:upgrade`, the reading of a 1.x config ([decisions/0024](decisions/0024-upgrade-conversion-rules.md)), the compatibility layer of the 1.x API and the Rector rules exist since [#139](https://github.com/folkloreinc/laravel-mediatheque/issues/139); the guide is completed as projects upgrade.

2.0 keeps the 1.x tables and converts them in place. A compatibility layer keeps the 1.x API working with deprecation notices until 3.0, so a project can upgrade first and clean up its code afterwards.

## Before you start

- The project runs **PHP 8.3+ and Laravel 12+** ([decisions/0001](decisions/0001-supported-versions.md)). Upgrade Laravel first if needed.
- The project requires a tagged 1.x release (`^1.1`), not `v1.1.x-dev`, and is on the latest one.
- Back up the database and know where the media files are stored.
- If the project uses `laravel-folklore`, check which of its versions supports mediatheque 2.0.
- The servers that run the queue have ffmpeg, and Poppler (`poppler-utils`) for the thumbnails of documents, which 1.x made with ImageMagick ([decisions/0025](decisions/0025-document-thumbnails-with-poppler.md)). Without Poppler, documents are ready without thumbnails, and their run fails with a message.

## Steps

1. **Require the new version:** `composer require folklore/laravel-mediatheque:^2.0`. The 1.x `config/mediatheque.php` keeps working: the package reads it in the 2.0 shape, with a deprecation notice, and makes each 1.x source a Laravel disk of the same name, unless you define one. If a disk you define, or Laravel's own `public`, doesn't store files where the source of the same name did, it logs a warning: define that disk as the source was (the default `public` source of 1.x in 2017 and 2018 was `public/files`, served at `/files`).
2. **Check the database:** `php artisan mediatheque:upgrade --check`. It changes nothing, and runs before the migration as well as after it. It lists:
   - orphan rows (runs, metadata, attachments of deleted media, jobs of deleted runs, files that nothing attaches) that block the foreign keys: the upgrade removes them, and never deletes a stored file;
   - 1.x sources that the disk of the same name doesn't store as they did;
   - the morph types of metadata and runs that are neither media nor files: if 1.x wrote your media as `App\Models\Media`, pass `--media-morph="App\Models\Media"` to every `mediatheque:upgrade` command (and `--file-morph` for files). The conversion refuses to start while metadata has such a type, unless you pass `--accept-unknown-morphs`;
   - runs whose serialized definition can't be read, and 1.x jobs with no 2.0 operation (custom classes, `WebFonts`, `MediaConvert`): their runs keep them in their history;
   - handles that don't follow the 2.0 rule;
   - files without a `source`, which 1.x stored on its default source, and the source they get;
   - files attached to models other than media through `files_pivot`, and files attached to several media;
   - media whose type has no 2.0 renditions: 1.x made web fonts of `font` media, and 2.0 has no rendition for fonts. Their files are kept.
3. **Publish and run the migration:** `php artisan mediatheque:install --upgrade`, then `php artisan migrate`. The migration adds columns; it renames no table and converts no data.
4. **Drain the queue**, then **convert the data:** `php artisan mediatheque:upgrade`. It gives files without a `source` the default source (`config('mediatheque.source')`), where 1.x stored them, and their media, splits grouped handles into handle and index, backfills `uid`, copies metadata into JSON, converts runs to the new statuses and JSON definitions, removes orphan rows and adds the foreign keys. It converts in batches (`--batch=500`), one transaction each: run it again to resume, or to check that nothing is left. A run that hadn't ended is `canceled`. The conversion overwrites a few 1.x columns that 2.0 reuses (`files.handle`, and a run's `definition`, `name`, `pipelinable_type` and `progress`): to go back to 1.x, restore your backup.
5. **Update the config:** `php artisan mediatheque:upgrade --config` rewrites `config/mediatheque.php` with only the project's overrides, in the 2.0 shape, and keeps the 1.x file in `config/mediatheque.php.1x.bak`. It reads the file as it is written, not as this machine's `.env` makes it: a key whose 1.x value is an `env()` call keeps that call, with the 1.x file's default (`'ffmpeg' => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg')`), and the environment variables the rewritten file no longer reads are listed, with the lines whose code reads this machine otherwise (`config()`, `getenv()`, a `require`). The disks it prints have values, not the `env()` calls of the 1.x sources. It prints the disks to add to `config/filesystems.php` for the 1.x sources, and what it dropped. A source is a Laravel disk in 2.0: the URL prefix of a 1.x source (`sources.*.url`), a CDN for instance, becomes its disk's `url`.
6. **Run the Rector rules:** `composer require --dev rector/rector`, then `vendor/bin/rector process app --config vendor/folklore/laravel-mediatheque/rector.php` (add `--dry-run` to see the changes first). They rename the 1.x trait, and rewrite `getFile('thumbnails.0')` to `file('thumbnails', 0)`, `hasFile('h264')` to `file('h264') !== null`, `getMetadatas()->get('width')->getValue()` and `getMetadata('width')->getValue()` to `metadata('width')`, `runPipeline($name)` to `Mediatheque::runPipeline($media, $name)`, `getOriginalFile()` to `original()`, a media's `getUrl()` to `original()?->url()` and a file's to `url()`, on expressions typed as media and files. They rewrite handles and metadata names written in the code only: one known at runtime may be a 1.x handle (`thumbnails.0`) or name (`colors`), which the 2.0 methods don't read. They leave those calls, `getFiles()`, whose keys are 1.x handles, `setOriginalFile()`, an ingest or a replacement, and `font_family_name`, which `font_names` lists: the compatibility layer keeps them working.
7. **Routes.** 2.0 has no upload routes ([decisions/0018](decisions/0018-ingest-only.md)). If the project used the package's upload routes, take files in its own routes, or Panneau 5's, and call `Mediatheque::ingest()`.
8. **Run the project's tests**, upload a file of each type, and check pages that show media.
9. **Fix the deprecations** at your own pace. They are logged on the `deprecations` channel.
10. **Later:** `php artisan mediatheque:upgrade --cleanup` drops the unused EAV `metadatas` table once you have checked the converted data. It refuses while the table holds metadata that no converted media or file has, and lists it.

## What changes

### Schema

| 1.x | 2.0 |
|---|---|
| `medias.id` exposed | `medias.uid` (`med_…`) exposed; `id` stays internal |
| — | `medias.status`, `visibility`, `metadata`, `transform`, `derived_from_id`, `deleted_at` |
| `files.source` null for the default source | The default source, written in the column: a file without a source has no URL, since 2.0 never guesses a file's disk |
| `files_pivot` links files to media | `files.media_id`, from the first media `files_pivot` attaches the file to; `files_pivot` is no longer used |
| `files.mime` 50 characters, `size` 32-bit | 255 characters, 64-bit |
| `files.handle` `thumbnails.0`, or only the pivot's handle | `handle` `thumbnails`, `index` 0, `role` (`original`, `thumbnail` for handles starting with `thumbnail`, `poster`, else `main`) |
| `metadatas` EAV table | `metadata` JSON on media and files; a file's `width`, `height` and `duration` in its columns |
| Metadata names `font_family_name`, `colors` | [Canonical keys](architecture.md#metadata-keys) `font_names`, `palette` |
| Any handle | A letter first, 64 characters at most ([decisions/0011](decisions/0011-renditions-on-remote-transcoders.md)): `--check` lists the handles that break the rule; rename those renditions |
| `pipelines.definition` serialized | JSON snapshot of the renditions the run ran (a custom job is the operation `v1.job`, with its class); `status`, `attempts`, `error` |
| `started`, `ended`, `failed` booleans | `status` enum: `completed`, `failed`, or `canceled` for what hadn't ended |
| No foreign keys | Foreign keys on files, pipeline jobs and pivots |

Existing media become `public` and `ready` (or `failed` if their last pipeline failed). Their files and URLs don't move. The 1.x columns stay until 3.0, and the columns that every row of a new install has (`uid`, a run's `transcoder`) are nullable on upgraded tables.

### API

| 1.x | 2.0 | Compatibility layer |
|---|---|---|
| `Folklore\Mediatheque\Support\Traits\HasMedias` | `Folklore\Mediatheque\Concerns\HasMedias` | Alias, with the 2.0 methods |
| `$model->medias()->sync($ids)`, `attach($id, ['handle' => …])` | `$model->syncMedias([$handle => $ids])`, `attachMedia($media, $handle)`: by handle ([decisions/0020](decisions/0020-attachments-by-handle-and-usages.md)) | The relation keeps working |
| `$media->getFile('h264')`, `hasFile()`, `getFiles()`, `getOriginalFile()`, `getThumbnails()` | `$media->file('h264')`, `files()`, `original()`, `thumbnail()` | Deprecated proxies; `getFiles()` is keyed by 1.x handle |
| `$media->getFile('thumbnails.0')` | `$media->file('thumbnails', 0)` | 1.x handles still resolve |
| `$media->files`, `with('files')`, `load('files')` (the relation) | `$media->files()`, a method of the contract; the relation is `mediaFiles` | Deprecated: the property reads the media's files, and eager loading `files` loads `mediaFiles`. `$media->files()` is no longer a relation: `$media->files()->where(…)` breaks |
| `$file->getHandle()`, `$file->getUrl()` | `$file->handle()` and `index()`, `$file->url()` | Deprecated proxies |
| `$media->setOriginalFile($path, $extraData)` | `Mediatheque::ingest($path)`, `$media->replaceOriginal($source)` | Deprecated proxy: ingests on an unsaved media, with the attributes set before and `$extraData`'s `name` and `data`; replaces the original of a saved one |
| `$media->getMetadatas()->get('width')->getValue()`, `getMetadata('width')` | `$media->metadata('width')` | Deprecated proxies; `font_family_name` and `colors` read `font_names` and `palette` |
| `$media->runPipeline($name)` | `Mediatheque::runPipeline($media, $name)` | Deprecated proxy |
| `$media->getUrl()` (the original's URL) | `$media->original()?->url()`; `$media->url()` is the main rendition's, else the original's | Deprecated proxy: the original's URL, as in 1.x |
| `app(TypeFactory::class)->typeFromPath($path)->newModel()` | `Mediatheque::ingest($path)` | Deprecated proxy: `Contracts\Type\Factory`, over the 2.0 types |
| `medias://{id}` references | `media://med_…` | `medias://` still resolves |
| Contracts bound with `app()->bind()` | Models set in `config('mediatheque.models')` | A binding of the media or file contract is honored while the config names the package's model |
| `Support\PipelineJob` subclasses | Custom operations of the `ffmpeg` transcoder | Manual (see below) |
| `Route::mediatheque()` loaded by default | No upload routes: the application takes files and calls `ingest()` | — |
| `media()` helper | `Mediatheque::ingest()` | Removed |

Each deprecated use is reported once per process, as an `E_USER_DEPRECATED` error that Laravel logs on the `deprecations` channel (`LOG_DEPRECATIONS_CHANNEL`).

### Custom pipeline jobs

A 1.x `PipelineJob` subclass becomes a custom operation:

1. Implement the operation contract of the `ffmpeg` driver (input file, options, output files).
2. Register it under a name (`app.bumper`) in `transcoders.ffmpeg.operations`.
3. Reference it from a rendition: `'bumper' => ['op' => 'app.bumper', …]`.

Custom operations run only on the `ffmpeg` driver.

### JSON

The package's `MediaResource` uses `uid` as `id` and returns `files` as an object keyed by handle ([decisions/0010](decisions/0010-json-resource.md)). It has no 1.x shape: front ends that expect the 1.x `laravel-folklore` shape get it from `laravel-folklore`'s own resource.
