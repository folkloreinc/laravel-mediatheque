# Current state of 1.x

Audit of `folklore/laravel-mediatheque` `v1.1` (tag `v1.1.1`), written to plan 2.0. Paths are relative to `src/Folklore/Mediatheque/` unless they start with `src/` or `tests/`.

## Summary

The 1.x concepts are sound and widely used: a **Media** with **Files** attached by **handle**, **Types** detected from the mime, **Pipelines** of jobs that produce new handles, and **Sources** that store files. The implementation has not kept up:

- pipeline definitions are stored with PHP `serialize()`, with class names in the database;
- metadata lives in an EAV table and is read synchronously during the upload request;
- pipeline orchestration is spread across observers, a global event, two models and two runner jobs;
- there is no private storage, no temporary URL and no access control;
- the HTTP layer is loaded by default and needs hardening (tracked privately);
- the code is mostly untyped, relies on service location inside models, and uses PSR-0 autoloading.

2.0 keeps the concepts and the tables, and replaces the implementation behind them (see [architecture.md](architecture.md) and [decisions/0002](decisions/0002-evolve-the-schema-in-place.md)).

## Size

| Directory | Files |
|---|---|
| `Contracts/` | 45 interfaces, 26 of them service interfaces |
| `Jobs/` | 19 |
| `Support/` | 13, including 6 traits |
| `Services/` | 12 |
| `Metadata/` | 10 |
| `Events/` | 7 |
| `Models/` | 6 |
| `Http/` | 5 |
| Other (`Observers/`, `Sources/`, `Types/`, `Console/`, root classes) | 17 |

About 134 PHP files and 7,800 lines. Tests: 60 test methods in 19 files (Testbench, SQLite in memory). Larastan runs at level 5 with a baseline of 94 entries (128 errors), mostly `property.notFound` and `method.notFound` on the models.

## Database

Eight migrations. Every table is prefixed with `mediatheque.table_prefix` (`mediatheque_` by default). Ids are auto-increment integers. There are **no foreign keys and no unique constraints**.

| Table | Columns | Notes |
|---|---|---|
| `medias` | `id`, `type`, `name`, `data` (json, added in 2023), timestamps | |
| `files` | `id`, `handle`, `source`, `name`, `path`, `mime` (50), `size` (unsigned int), timestamps | `mime` truncates long OOXML types; `size` overflows above 4 GB |
| `files_pivot` | `file_id`, `morphable`, `handle`, `order` | The handle is stored twice, on `files` and on the pivot |
| `medias_pivot` | `media_id`, `morphable`, `handle`, `order` | Used by the `HasMedias` trait on application models |
| `metadatas` | `morphable`, `name`, `type`, `value_string`, `value_text`, `value_integer`, `value_float` (8,4), `value_boolean`, `value_json` | EAV; no unique index on (`morphable`, `name`); `float(8,4)` caps durations at about 2 h 46 min on MySQL |
| `pipelines` | `name`, `pipelinable`, `definition` (serialized PHP object), `started`, `ended`, `failed`, `failed_exception`, `progress` (never written), dates | Renaming a class breaks existing rows; `unserialize()` on database content |
| `pipelines_jobs` | same, plus `pipeline_id` | `definition` is JSON with the job class name; `pipeline_id` has no index |

Deleting a media deletes its files, but keeps its pipelines, metadata and pivot rows.

## Public API

Applications use more than the contracts. Everything below is public API in practice.

### Models and traits

- `Models\Media` and `Models\File`, bound to `Contracts\Models\Media` and `File`. Applications rebind them to their own subclasses, which add columns, casts, soft deletes, tags and relations.
- `Models\Metadata`, `Pipeline` and `PipelineJob`, also rebindable.
- Traits: `HasFiles` (`getFiles`, `getFile`, `hasFile`, `setOriginalFile`, `getOriginalFile`, `setFile`, `addFile`, `removeFile`), `HasMetadatas` (`getMetadatas`, `getMetadata`, `setMetadata`), `HasPipelines` (`runPipeline`, `getPipelines`, `hasPendingPipeline`), `HasThumbnails`, `HasUrl`.
- `Support\Traits\HasMedias`, used on application models: a `medias()` morph-to-many relation on `medias_pivot`, with `handle` and `order`.
- Model classes are resolved by instantiating the bound contract (`get_class(app(Contract))`), so relations follow the application's bindings.

### File handles

- `original` is the uploaded file.
- A pipeline job's output is attached under the job's name: `h264`, `webm`, `hevc`, `hls`, `ttf`, `woff`, `woff2`.
- A job that returns several files attaches them as `{name}.{index}` (`thumbnails.0` to `thumbnails.4`) or under their string keys. Some applications still read a single `thumbnails` handle.
- Consumers match thumbnails loosely (by regex on `thumbnail`), because there is no accessor for "the" thumbnail.

### Configuration

`config/mediatheque.php` is published by most applications, often as a full copy of the defaults. Keys: `table_prefix`, `source`, `sources.*` (`driver`, `path`, `url`, `disk`, `visibility`), `file_path_format`, `types.*` (`type`, `model`, `pipeline`, `can_upload`, `animated_image`, `mimes`, `metadatas`), `metadatas.*`, `pipelines.*` (`queue`, `auto_start`, `unique`, `from_file`, `jobs`), `routes.*`, `jobs.timeout`, `jobs.tries`, `services.*`. Applications customise job options (`max_width`, `quality`, `queue`), add named pipelines and register custom types.

### Extension points

- `TypeManager`, `PipelineManager` and `MetadataManager` accept `extend(name, closure|class|array)`; `SourceManager` accepts `extend(driver, closure)`.
- Custom types extend `Support\Type` and override `pathIsType()`. Type order in the config decides detection.
- Custom jobs extend `Support\PipelineJob`, and pipelines can be built with `Support\Pipeline::fromJobs()`.
- Each service is a singleton under `mediatheque.services.*`, aliased to its contracts. Some classes resolve concrete services instead (`PathFormatter` in `PipelineJob`, `HLS` and `WebFonts`; `instanceof LocalSource`), so rebinding them is partial.

### Other entry points

- Facade `Mediatheque`; helpers `mediatheque()` and `media($path)` (a very generic global name, which creates and saves a media).
- Events: `MediaCreated`, `MediaUpdated`, `MediaSaved`, `MediaDeleted`, `MediaRestored` (typed to the concrete model), `FileAttached`, `FileDetached`.
- Route macro `Route::mediatheque()`; command `mediatheque:run_pipeline`.

### References in application data

Applications built on `laravel-folklore` store media references as `medias://{id}` inside JSON columns and resolve them through `medias()`. These references live in production data, so 2.0 must keep resolving them.

### JSON shape

`laravel-mediatheque` has no resource of its own. The shape front ends consume comes from `laravel-folklore`'s `MediaResource`:

```
{ id, type, name, url, thumbnail_url,
  metadata: { filename, size, mime, width?, height?, duration?, pagesCount? },
  sizes?: [...],                      // images only
  files: [ { id, handle, name, url, mime, size } ] }
```

`panneau-js` 3 and 4 read `files` as an object keyed by handle (`files.h264.url`), which only works when the resource is asked for a map. Applications also write their own resources.

## Types and metadata

- `TypeManager::typeFromPath()` returns the first type, in config order (`audio`, `document`, `font`, `video`, `image`), whose mime patterns match.
- `document` matches `application/octet-stream` and `text/plain`, so any unknown binary becomes a document.
- `TypeManager::type()` returns an empty type for an unknown name instead of failing.
- Metadata readers: `duration` (ffprobe), `dimension` (Imagick, `getimagesize`, ffprobe, imagine-svg), `pages_count` (Imagick and Ghostscript), `font_family_name` (otfinfo), `waveform` (audiowaveform), `audio_tracks_count` (ffprobe), `colors` (color-thief).
- Readers run **synchronously when the original file is set**, inside the upload request. Errors are logged and swallowed unless `debug` is on.
- The SVG dimension service returns a list, so its metadata is stored as `0` and `1` instead of `width` and `height`.

## Pipelines and jobs

| Job | Outputs | Runs with |
|---|---|---|
| `Video\H264`, `Video\HEVC`, `Video\WebM` | `h264`, `hevc`, `webm` | php-ffmpeg |
| `Video\Thumbnails` | `thumbnails.0` to `.4` | ffmpeg |
| `Video\HLS` (not in the default config) | `hls` (playlist, segments next to it) | php-ffmpeg-video-streaming |
| `Video\MediaConvert` | `h264`, `webm` | AWS SDK; polls with `sleep(10)` inside the worker |
| `Audio\Thumbnails` | `thumbnails` | audiowaveform |
| `Document\Thumbnails` | `thumbnails.{page}` | Imagick |
| `Font\WebFonts` | `ttf`, `woff`, `woff2`… | an external `convertFonts.sh` script, not shipped |

How they run:

- `RunPipeline` creates the job rows; `RunPipelineJob` claims each row and runs the job synchronously inside it. The job's return value carries its files.
- A job waits for the handle named by `from_file`. It starts when `FileAttached` fires for that handle, or when the job that produces it ends. Waiting jobs fail when their producer fails (fixed in 1.1.0).
- The `image` type points to an `image` pipeline that the default config does not define: an empty pipeline is created and never ends.
- MediaConvert ignores its configured outputs, reads the default source instead of the file's, and treats `ERROR` as complete.

## Storage and URLs

- `LocalSource` (a directory and a URL prefix) and `FilesystemSource` (a Laravel disk and a path prefix).
- `PathFormatter` builds paths from `file_path_format` (`{type}/{date(Y-m-d)}/{id}-{date(his)}.{extension}`). The file row is saved first to get its id. The formatter does not receive the media, so derived file paths cannot be prefixed with application data (a tenant id, for example).
- URLs are always public (`url` prefix or `disk->url()`). There is no private visibility, no temporary or signed URL and no access control.

## HTTP

- Routes are loaded by default, through `routes/mediatheque.php` when published or the package's `routes.php` otherwise. Applications that don't want them set `routes.map` to `null`.
- `MediaController` fails on every action (it reads a route action the router never sets). The `pull` route is a stub.
- The upload and route hardening is tracked privately.

In practice, applications upload through their own controllers (usually Panneau's upload field, through `laravel-folklore`'s `Medias` repository) and return `laravel-folklore`'s `MediaResource`.

## Dependencies

| Package | Use |
|---|---|
| `php-ffmpeg/php-ffmpeg` | Video and audio jobs, metadata |
| `aminyazdanpanah/php-ffmpeg-video-streaming` | HLS only |
| `aws/aws-sdk-php` | MediaConvert only |
| `contao/imagine-svg` | SVG dimensions |
| `symfony/process`, `symfony/mime`, `symfony/http-foundation` | Shell jobs, mime detection, uploads |
| `ramsey/uuid` | One call, replaceable by `Str::uuid()` |
| `guzzlehttp/guzzle` | Unused ([#21](https://github.com/folkloreinc/laravel-mediatheque/issues/21)) |
| `ksubileau/color-thief-php` | Used by the default `colors` reader, but only in `require-dev` |

Only `illuminate/support` is declared, while the code uses the database, routing, queue, console and filesystem components. `league/flysystem-aws-s3-v3` is used without being declared.

## Who uses 1.x

- `laravel-folklore` v1.1: it extends `Media` and `File`, uses `HasMedias` on its models, stores `medias://` references, wraps media in entities that read handles, and defines the `MediaResource` shape.
- About 40 applications require the package. Fewer than 20 are active, and most of those get it through `laravel-folklore`. The active ones run Laravel 9 to 13; about ten run Laravel 12 or later.
- The pilot project of 2.0 starts directly on 2.0 and has no 1.x data.

## Known limits, by impact

1. HTTP layer loaded by default and in need of hardening (tracked privately).
2. Serialized pipeline definitions with class names in the database.
3. Pipeline orchestration that is hard to follow and to make reliable: observers, a global event, pseudo-jobs run synchronously, `progress` never written, MediaConvert blocking a worker, the `image` pipeline never ending.
4. Synchronous metadata extraction in the upload request, with swallowed errors.
5. Schema limits: no foreign keys, int sizes, short mime, `float(8,4)` durations, duplicated handle, orphans on delete.
6. EAV metadata: duplicated on media and file, no uniqueness, dimensions split into rows.
7. Service location and option bags everywhere; behaviour that depends on concrete classes.
8. Untyped contracts that rely on Eloquent magic.
9. Public-only storage.
10. Packaging: PSR-0, dead code, a generic `media()` helper, undeclared dependencies.
