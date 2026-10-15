# Architecture of 2.0

Design of `folklore/laravel-mediatheque` 2.0. The APIs below are drafts: each one is written as a contract with tests before it is implemented, and may change until the release candidate. The reasons behind each choice are in [decisions/](decisions/).

## Goals

- **Easy.** One command to install, defaults that work without configuration, a short API for the common cases (ingest a file, attach it, get a URL).
- **Powerful.** Declarative renditions, interchangeable transcoders, outputs that are not files, derived media, a usage graph, events at every step.
- **Robust.** Asynchronous by default, with an explicit state for each rendition. Callbacks and jobs are safe to retry. Foreign keys, 64-bit sizes, no `serialize()`. Larastan at the maximum level and `Mediatheque::fake()` for applications.
- **Secure by default.** Content-based type detection, validated uploads, sanitized SVG, private media with temporary URLs, HTTP routes that are off until the application enables them behind its own authorization.
- **Easy to upgrade to.** Same tables, a migration that converts them, a compatibility layer for the 1.x API and Rector rules (see [upgrade.md](upgrade.md)).

## Boundaries

| Package | Responsibility |
|---|---|
| `laravel-mediatheque` | Ingest, store, probe and process media; keep their files, renditions and metadata; control access to them; resolve references |
| [`laravel-image`](https://github.com/folkloreinc/laravel-image) 2 | Image URLs and on-the-fly image renditions (presets, crops, formats), resolved from a media id |
| [Eclair](https://github.com/urbania-media/eclair) (through `laravel-eclair`) | A shared processing server: one implementation of the `Transcoder` contract |
| [Panneau](https://github.com/folkloreinc/panneau-js) 5 | The admin's media API and UI, through an adapter over this package |
| [`laravel-folklore`](https://github.com/folkloreinc/laravel-folklore) | Entities and resources of Folklore sites, built on this package |
| The application | Tenancy, its own API and policies, the models that own media |

The package knows nothing about tenants, users or organisations ([decisions/0009](decisions/0009-package-boundaries.md)).

## Overview

```
            ┌──────────── ingest ────────────┐
upload ───▶ │ validate → store original      │ ──▶ Media (pending)
disk path   │ (content-based type, sanitize) │
URL         └────────────────────────────────┘
                             │ queued
                             ▼
                     probe (Prober) ──▶ metadata, type   (Media: processing)
                             │
                             ▼
              pipeline of the type: renditions ──▶ Transcoder (ffmpeg | Eclair | MediaConvert)
                             │                          │ callbacks (idempotent)
                             ▼                          ▼
                 files and metadata outputs ◀── recorder ──▶ events
                             │
                             ▼
                      Media (ready | failed)
                             │
             url() / temporaryUrl() / laravel-image / MediaResource
```

## Domain model

### Media

A media is one piece of content: a video, an audio file, an image, a document, a font or a caption track.

| Attribute | Notes |
|---|---|
| `uid` | Public id, a prefixed ULID: `med_01J…` ([decisions/0003](decisions/0003-identifiers-and-references.md)). Generated before the media is saved, so paths can use it. Route key. |
| `type` | `video`, `audio`, `image`, `document`, `font`, `captions`, or a custom type |
| `name` | Display name, from the upload's file name by default |
| `status` | `MediaStatus` enum: `pending`, `processing`, `ready`, `failed` |
| `visibility` | `Visibility` enum: `public` or `private` ([decisions/0007](decisions/0007-visibility-and-urls.md)) |
| `metadata` | JSON: `width`, `height`, `duration`, `frame_rate`, `codecs`, `pages_count`, `font_names`, `palette`, `blurhash`, `locale`… |
| `data` | JSON for the application (kept from 1.x) |
| `derived_from_id` | The media it was derived from (a clip, a caption track) |
| `deleted_at` | Soft deletes |

```php
$media->uid;                          // "med_01JAB…"
$media->status;                       // MediaStatus::Ready
$media->metadata('duration');         // 2851.2
$media->original();                   // File
$media->file('h264');                 // File|null
$media->file('thumbnails', 2);        // third file of a group
$media->files('thumbnails');          // Collection<File>
$media->thumbnail();                  // the first thumbnail or poster, whatever the type
$media->url('h264');                  // string|null, see Visibility and URLs
```

### Files and renditions

A file belongs to one media. Its **handle** says what it is: `original` for the ingested file, the rendition's name for the others.

| Attribute | Notes |
|---|---|
| `handle` | `original`, `h264`, `thumbnails`… Letters, digits, `_` and `-`, no dots |
| `index` | Position in a group (`thumbnails` 0 to 4, one per document page). `null` for a single file. Replaces the 1.x `thumbnails.0` handles ([decisions/0004](decisions/0004-renditions.md)) |
| `role` | `main`, `thumbnail`, `poster`, `captions`, `playlist`… |
| `source`, `path` | Where it is stored. A multi-part rendition (HLS) stores its main file; `parts_count` says how many files sit next to it |
| `name`, `mime` (255), `size` (64-bit), `checksum` | |
| `width`, `height`, `duration`, `bitrate` | Filled from the rendition's probe or from the transcoder's output |
| `metadata` | JSON for anything else |
| `definition_hash` | Hash of the rendition definition that produced it, so an unchanged rendition is not produced twice |
| `pipeline_job_id` | The job that produced it |
| `provider`, `provider_ref` | For a remote rendition, played from a provider's URL instead of a file on a source (prepared in 2.0, used by a later Cloudflare Stream driver) |

Outputs that are not files (palette, blurhash, scenes, a transcript's words) are written to the media's or the file's `metadata`.

### Pipelines and jobs

A **rendition** is a declarative description of one output: an operation, its options, the rendition it reads from, and a condition. A **pipeline** is a named set of renditions, run by one transcoder. Each type has a default pipeline.

A **pipeline run** (`pipelines` table) records one execution:

| Attribute | Notes |
|---|---|
| `name`, `pipelinable` | The pipeline and the media it runs on |
| `transcoder`, `external_id` | The driver and its job id (an Eclair job, a MediaConvert job) |
| `definition` | JSON snapshot of the renditions, never `serialize()` |
| `status` | `PipelineStatus` enum: `pending`, `running`, `completed`, `failed`, `canceled` |
| `progress`, `attempts`, `error` (`{code, message}`), `started_at`, `ended_at` | |

Each rendition gets a **pipeline job** (`pipelines_jobs`) with its own `status` (`queued`, `running`, `completed`, `failed`, `skipped`, `canceled`), `progress`, `attempts` and `error`. A failed job fails the jobs that read from it; independent jobs continue. `skipped` is a normal outcome: a condition did not match the probed input (an audio-only video, a short clip).

### Attachments and usages

Application models attach media through the `HasMedias` trait, on the 1.x `medias_pivot` table (`media_id`, `morphable`, `handle`, `order`). The pivot is also the **usage graph**:

```php
$post->attachMedia($media, 'cover');
$post->syncMedias(['cover' => $cover, 'gallery' => [$a, $b]]);
$post->media('cover');                // Media|null
$post->medias('gallery');             // Collection<Media>

$media->usages();                     // the models that use it
$media->isUsed();
```

A media that is in use can't be deleted unless the deletion is forced.

### References

Rich text and JSON data refer to media by reference, never by URL:

- `media://med_01J…` is the 2.0 form.
- `medias://123` (1.x, stored by `laravel-folklore`) keeps resolving, by internal id.

`MediaReference::parse()`, `Mediatheque::resolve()` and `Mediatheque::resolveMany()` handle both forms, in batches.

## Types

A type has a name, mime patterns, a default pipeline, the metadata it expects, and ingest rules (maximum size, allowed mimes). Detection reads the content, never the client's file name or extension:

1. the mime is sniffed from the content (`symfony/mime`, with `fileinfo`);
2. the first type whose patterns match wins; a type can refine detection (`Video` accepts animated GIF and WebP when enabled);
3. the stored extension is derived from the detected mime;
4. a file that matches no type is rejected, instead of becoming a `document`.

`Mediatheque::type('unknown')` throws. Custom types register in the config or with `Mediatheque::extendType()`.

New in 2.0: the `captions` type (WebVTT and SRT, stored as VTT), with a `locale` and an optional `derived_from` pointing to the media it captions.

## Ingest

Every way in ends in the same `ingest()` step, which validates, stores the original and creates the media.

```php
$media = Mediatheque::ingest($request->file('file'));
$media = Mediatheque::ingest(Source::disk('s3', 'uploads/abc.mov'));
$media = Mediatheque::ingest(Source::url('https://example.com/video.mp4'));
$media = Mediatheque::ingest($file, visibility: Visibility::Private, pipeline: 'video', name: 'Episode 1');
```

| Source | Notes |
|---|---|
| `UploadedFile`, local path, stream | Copied to the media's source |
| A file already on a disk (`Source::disk()`) | Moved or copied without going through PHP when the disk allows it. This is how direct uploads finish |
| A URL (`Source::url()`) | Downloaded in a queued job, with an SSRF guard (public addresses only, size and time limits, redirects checked) |

Ingest steps:

1. Detect the type from the content; check the type's maximum size and allowed mimes.
2. Sanitize SVG. For images, remove GPS data from the stored original by default (`ingest.images.strip_location`).
3. Build the path with the path formatter, which receives the media (`{media.uid}`, or an application attribute such as a tenant id).
4. Store the original, create the media (`pending`) and dispatch `MediaCreated`.
5. Queue the probe, then the type's pipeline.

Ingest runs in a transaction: a failure leaves neither a stored file nor a row behind.

### Direct and resumable uploads

Large files should not go through PHP. The package provides, as opt-in HTTP endpoints ([decisions/0006](decisions/0006-ingest-and-uploads.md)):

- **Presigned multipart uploads** to an S3-compatible disk, for Uppy's `AwsS3` plugin: create an upload (`upl_…`), sign its parts, complete it, then ingest from the disk. Interrupted uploads resume from the last part.
- **A TUS adapter** (later alpha), for clients that need the TUS protocol. It writes to a disk and ends with the same ingest step.

Uploads are tracked in an `uploads` table (status, disk, path, expected size, expiry). `mediatheque:prune` removes abandoned ones.

## Probe

A `Prober` reads a file's technical metadata: kind, mime, dimensions, rotation, duration, frame rate, codecs, bitrate, page count, font names, animation.

- The local prober uses ffprobe, Imagick or `getimagesize`, and otfinfo.
- Eclair's prober uses its `probe` endpoint.
- The probe runs in a queued job. A slow probe leaves the media `pending` until it completes.

Probe results fill the media's `metadata`, and the file's `width`, `height`, `duration` and `bitrate`.

## Renditions and pipelines

Renditions are declared in the config, or in PHP for applications that prefer classes. Operation names and options follow Eclair's vocabulary (`video.transcode`, `video.thumbnails`, `video.hls`, `audio.waveform`…), so a pipeline means the same thing on every transcoder.

```php
'pipelines' => [
    'video' => [
        'transcoder' => env('MEDIATHEQUE_TRANSCODER', 'ffmpeg'),
        'renditions' => [
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 1920],
            'webm' => ['op' => 'video.transcode', 'codec' => 'vp9', 'max_width' => 1920],
            'poster' => ['op' => 'video.poster', 'role' => 'poster'],
            'thumbnails' => ['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail'],
        ],
    ],
    'audio' => [
        'renditions' => [
            'mp3' => ['op' => 'audio.transcode', 'codec' => 'mp3'],
            'waveform' => ['op' => 'audio.waveform', 'role' => 'thumbnail'],
        ],
    ],
],
```

A rendition can set `from` (another rendition), `when` (`kind`, `mime`, `min_duration`, `max_duration`), `keep: false` (an intermediate file) and `queue`.

Renditions are **idempotent**. Running a pipeline again only produces the renditions that are missing, failed, or whose definition changed (`definition_hash`). An application can also ask for one rendition on demand:

```php
$media->ensureRendition('vertical');          // queued if missing
Mediatheque::runPipeline($media, 'video');    // the whole pipeline again
```

### Derived media

A clip is a new media derived from another one, with its own pipeline:

```php
$clip = Mediatheque::derive($video, Clip::make(start: 12.5, end: 56.0)->crop(Crop::ratio('9:16')));
$clip->derivedFrom;                            // the source media
```

Caption tracks are derived media of type `captions`. A transcription operation (through a transcoder that supports it, such as Eclair) creates them.

## Transcoders

The `Transcoder` contract is asynchronous ([decisions/0005](decisions/0005-asynchronous-transcoders.md)): a driver receives a whole pipeline run, starts it, and reports each rendition through a recorder. The local driver is the special case that runs in the application's queue.

```php
interface Transcoder
{
    /** Operations this driver can run. */
    public function supports(string $operation): bool;

    /** Optional abilities: progress, cancel, probe, remote renditions. */
    public function capabilities(): Capabilities;

    /** Start the run. Returns the driver's job id, if it has one. */
    public function submit(PipelineRun $run): ?string;

    public function cancel(PipelineRun $run): void;
}

interface Recorder
{
    public function started(PipelineJob $job): void;
    public function progress(PipelineJob $job, float $progress): void;
    public function completed(PipelineJob $job, Outputs $outputs): void;  // files and metadata outputs
    public function skipped(PipelineJob $job, string $reason): void;
    public function failed(PipelineJob $job, TranscoderError $error): void;
}
```

Recorder calls are idempotent: a duplicated webhook or a retried queue job changes nothing the second time. Each call checks the job's attempt and status before writing.

| Driver | Runs | Completion | Package |
|---|---|---|---|
| `ffmpeg` | ffmpeg, ffprobe, Imagick, audiowaveform and fonttools in the application's queue | Each rendition is a queued job; dependent renditions are dispatched when their input completes | This package |
| `eclair` | The whole run as one Eclair job (task graph, `when` conditions, outputs written to the application's storage) | Signed webhooks, deduplicated | [`laravel-eclair`](https://github.com/urbania-media/eclair/issues/13) |
| `mediaconvert` | AWS Elemental MediaConvert jobs for video renditions | EventBridge notifications through a webhook, or a scheduled poller. Never `sleep()` in a worker | This package, with `aws/aws-sdk-php` (suggested) |
| `cloudflare-stream` (2.x) | Upload to Cloudflare Stream; remote renditions | Webhook | Later, if a project needs it |

One transcoder runs a whole pipeline run. A pipeline whose renditions need two transcoders is split into two pipelines. At boot, `mediatheque:doctor` checks that each pipeline's transcoder supports its operations.

## Visibility and URLs

A media is `public` or `private`. Its files follow its visibility on their source (object ACL on S3-compatible disks). Changing the visibility updates the files in a queued job.

```php
$media->url();                                    // the main rendition, or the original
$media->url('h264');
$media->temporaryUrl('h264', now()->addMinutes(30));
$media->file('h264')->url();
```

- **Public:** the source's URL, through its CDN prefix when configured.
- **Private:** `url()` returns a temporary URL with the configured lifetime (`urls.temporary_ttl`). Disks that support temporary URLs (S3) sign them themselves. Local disks go through a signed route that streams the file.
- **CDN signing** goes through a `UrlSigner` contract, for CDNs that sign their own URLs.
- **Private HLS** needs signed segments: the playlist is rewritten through a signed route, or the CDN signs by token. This is planned for the HLS alpha.

### Images and `laravel-image`

Image renditions (sizes, crops, formats) are not stored by the mediatheque: `laravel-image` 2 renders them on the fly from the original. The mediatheque provides the `laravel-image` source resolver, which turns `med_…` into a source and a path, so image URLs never expose storage paths. Video posters and thumbnails, which come out of a pipeline, are files of the media and go through `laravel-image` the same way.

## JSON resource

The package ships the canonical JSON shape of a media ([decisions/0010](decisions/0010-json-resource.md)), so `laravel-folklore`, Panneau 5 and applications share one contract:

```json
{
  "id": "med_01JAB…",
  "type": "video",
  "name": "Episode 1",
  "status": "ready",
  "visibility": "public",
  "url": "https://cdn.example.com/…/h264.mp4",
  "thumbnail_url": "https://cdn.example.com/…/poster.jpg",
  "metadata": { "filename": "episode-1.mov", "mime": "video/quicktime", "size": 734003200, "width": 1920, "height": 1080, "duration": 2851.2 },
  "files": {
    "original": { "handle": "original", "url": "…", "mime": "video/quicktime", "size": 734003200 },
    "h264": { "handle": "h264", "url": "…", "mime": "video/mp4", "size": 210763776, "width": 1920, "height": 1080 },
    "thumbnails": [ { "handle": "thumbnails", "index": 0, "url": "…" } ]
  },
  "created_at": "2026-10-15T14:00:00Z"
}
```

`files` is an object keyed by handle; groups are lists. A `legacy` option emits the 1.x `laravel-folklore` shape (`id` as the internal id, `files` as a list) during the upgrade.

## HTTP

Routes are off by default ([decisions/0008](decisions/0008-secure-http.md)). An application that wants them registers them in its own routes file, inside its own middleware:

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::mediatheque();                       // or ->only(['uploads', 'medias'])
});
```

| Route | Purpose |
|---|---|
| `POST uploads`, `POST uploads/{upload}/parts`, `POST uploads/{upload}/complete` | Presigned multipart uploads |
| `POST medias` | Small uploads through PHP |
| `GET medias/{media}`, `PATCH medias/{media}`, `DELETE medias/{media}` | Read, rename, change visibility, delete |
| `GET files/{file}` | Signed streaming of private files on local disks |

Every action is authorized through a `Media` policy (`create`, `view`, `update`, `delete`). Without a policy, everything is denied. Requests are validated, responses use the JSON resource, and errors are `application/problem+json`.

## Events

| Event | When |
|---|---|
| `MediaCreated`, `MediaUpdated`, `MediaDeleted`, `MediaRestored` | Model lifecycle |
| `MediaProbed` | Probe results stored |
| `PipelineStarted`, `PipelineCompleted`, `PipelineFailed` | Pipeline run |
| `RenditionCompleted`, `RenditionFailed`, `RenditionSkipped` | Each rendition |
| `MediaReady` | Every rendition of the default pipeline ended, at least the main one completed |
| `MediaVisibilityChanged`, `OriginalReplaced` | |

Events carry contracts, not concrete classes, so they work with the application's models.

## Testing

```php
Mediatheque::fake();

$media = Mediatheque::ingest(UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'));

Mediatheque::assertIngested(fn (Media $m) => $m->type === 'video');
Mediatheque::assertPipelineRan($media, 'video');
Mediatheque::fake()->failRendition('h264');
```

The fake stores files on a fake disk, returns probe fixtures, and completes renditions with placeholder files, without ffmpeg or a queue worker.

## Extension

- **Models.** `Media`, `File`, `PipelineRun` and `PipelineJob` are bound to contracts and set in the config (`models.media`…). Applications extend them to add columns, scopes and relations.
- **Migrations.** `mediatheque:install` publishes the migrations; the package does not load them itself. The application owns its schema and can add its own columns (a tenant id, for example) in the same migration.
- **Global scopes.** Package workers load models through the morph map and without global scopes, so a tenant scope that depends on the current request does not hide media from queued jobs.
- **Path formatter.** Receives the media and the file: `{media.uid}`, `{media.tenant_id}`, `{type}`, `{handle}`, `{index}`, `{extension}`, `{date(Y/m)}`.
- **Types, renditions, transcoders, probers, URL signers and sources** register through the config or `Mediatheque::extend*()`.

## Commands

| Command | Purpose |
|---|---|
| `mediatheque:install` | Publish the config and migrations, check the environment |
| `mediatheque:doctor` | Binaries and versions, disks and visibility, queue `retry_after` against job timeouts, transcoders against pipelines |
| `mediatheque:upgrade` | Convert a 1.x database (see [upgrade.md](upgrade.md)); `--check` lists what would change |
| `mediatheque:run-pipeline` | Run a pipeline on media, by type or uid |
| `mediatheque:prune` | Force-delete soft-deleted media after the grace period, abandoned uploads, orphan files |

## Deletion and replacement

- Deleting a media soft-deletes it. Its files stay until `mediatheque:prune` force-deletes it after `prune.after_days`. Force deletion removes files, renditions, pipeline runs, metadata and pivot rows.
- A media in use can't be deleted unless the deletion is forced.
- `$media->replaceOriginal($source)` keeps the `uid`, stores the new original, deletes the renditions in a queued job, and runs the pipeline again.

## Configuration

The 1.x keys keep their meaning where the concept is unchanged (`table_prefix`, `source`, `sources`, `file_path_format`, `types`, `pipelines`, `routes`, `jobs`). New keys: `models`, `ingest`, `urls`, `transcoders`, `probe`, `uploads`, `prune`.

The package merges its defaults deeply with the application's file, so a published config only needs its overrides. The 1.x shapes of `types` and `pipelines` are read with deprecation warnings (see [upgrade.md](upgrade.md)).

## Package layout

```
src/                                  PSR-4: Folklore\Mediatheque\
  Contracts/                          Public contracts
  Models/                             Media, File, PipelineRun, PipelineJob, Upload
  Enums/                              MediaStatus, Visibility, PipelineStatus, JobStatus
  Ingest/                             Sources, validation, sanitizers, path formatter
  Probe/                              Prober contract implementations
  Pipelines/                          Renditions, runner, recorder
  Transcoders/Ffmpeg/, MediaConvert/
  Urls/                               UrlGenerator, signers
  References/                         media:// and medias:// parsing and resolution
  Http/                               Opt-in routes, controllers, requests, resources
  Concerns/                           HasMedias
  Compat/                             1.x compatibility layer (deprecated)
  Testing/                            MediathequeFake
  Console/
config/mediatheque.php
database/migrations/
```

## Dependencies

- Required: `illuminate/*` components the code uses (declared), `symfony/mime`, `symfony/process`, an SVG sanitizer.
- The ffmpeg driver builds its commands with `symfony/process`, without `php-ffmpeg` or `php-ffmpeg-video-streaming`.
- Suggested: `ext-imagick`, `aws/aws-sdk-php` (MediaConvert), `league/flysystem-aws-s3-v3` (S3 disks and presigned uploads), `folklore/laravel-eclair`, `folklore/laravel-image`.
- Removed: Guzzle, `ramsey/uuid`, `contao/imagine-svg`.

## Supported versions

PHP 8.3+ and Laravel 12+ ([decisions/0001](decisions/0001-supported-versions.md)).
