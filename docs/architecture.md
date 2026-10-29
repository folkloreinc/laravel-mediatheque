# Architecture of 2.0

Design of `folklore/laravel-mediatheque` 2.0. The APIs below are drafts: each one is written as a contract with tests before it is implemented, and may change until the release candidate. The reasons behind each choice are in [decisions/](decisions/).

## Goals

- **Easy.** One command to install, defaults that work without configuration, a short API for the common cases (ingest a file, attach it, get a URL).
- **Powerful.** Declarative renditions, interchangeable transcoders, outputs that are not files, derived media, a usage graph, events at every step.
- **Robust.** Asynchronous by default, with an explicit state for each rendition. Callbacks and jobs are safe to retry. Foreign keys, 64-bit sizes, no `serialize()`. Larastan at the maximum level and `Mediatheque::fake()` for applications.
- **Secure by default.** Content-based type detection, validated files, sanitized SVG, private media with temporary URLs, HTTP routes that are off until the application enables them behind its own authorization.
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
| `metadata` | JSON, with the [canonical keys](#metadata-keys): `width`, `height`, `duration`, `frame_rate`, `video_codec`, `pages_count`, `font_names`, `palette`, `thumbhash`, `locale`… |
| `data` | JSON for the application (kept from 1.x) |
| `transform` | JSON: the non-destructive edits of an image, as Panneau 5's editor writes them (`crop`, `rotate`, `flipX`, `flipY`, `focalPoint`), or null ([decisions/0022](decisions/0022-image-transform-and-orientation.md)) |
| `derived_from_id` | The media it was derived from (a clip, a caption track) |
| `deleted_at` | Soft deletes |

```php
$media->uid();                        // "med_01JAB…"
$media->status();                     // MediaStatus::Ready
$media->metadata('duration');         // 2851.2
$media->transform();                  // ['crop' => [...], 'rotate' => 90, 'focalPoint' => [...]] or null
$media->original();                   // File
$media->file('h264');                 // File|null
$media->file('thumbnails', 2);        // third file of a group
$media->files('thumbnails');          // Collection<File>
$media->thumbnail();                  // the poster, else the first thumbnail, whatever the type
$media->url('h264');                  // string|null, see Visibility and URLs
$media->processing();                 // Processing|null: the run in progress
```

`processing()` is the media's latest run that hasn't ended, as an editor's interface shows it ("transcoding: 42 %"), or null when nothing runs ([decisions/0023](decisions/0023-progress-of-runs.md)). It is a readonly `Pipelines\Processing`: the media's `status`, the run's uid (`run`), its `pipeline` (`video`, `probe`…), its `progress` (`?float`) and the `rendition` that is running (`h264`, or null while none started). Clients poll it: the package pushes nothing.

### Files and renditions

A file belongs to one media. Its **handle** says what it is: `original` for the ingested file, the rendition's name for the others.

| Attribute | Notes |
|---|---|
| `handle` | `original`, `h264`, `thumbnails`… A letter, then letters, digits, `_` and `-`; 64 characters at most; no dots ([decisions/0011](decisions/0011-renditions-on-remote-transcoders.md)) |
| `index` | Position in a group (`thumbnails` 0 to 4, one per document page). `null` for a single file. Replaces the 1.x `thumbnails.0` handles ([decisions/0004](decisions/0004-renditions.md)) |
| `role` | `main`, `thumbnail`, `poster`, `captions`, `playlist`… |
| `source`, `path` | Where it is stored: the path the transcoder reports, never rebuilt. A multi-part rendition (HLS) stores its main file; `parts_count` says how many files sit next to it |
| `name`, `mime` (255), `size` (64-bit), `checksum` | |
| `width`, `height`, `duration`, `bitrate` | From the transcoder's output. When the output lacks them, the file is probed for the missing fields |
| `metadata` | JSON for anything else |
| `definition_hash` | Hash of the rendition definition that produced it, so an unchanged rendition is not produced twice |
| `pipeline_job_id` | The job that produced it |
| `provider`, `provider_ref` | For a remote rendition, played from a provider's URL instead of a file on a source (prepared in 2.0, used by a later Cloudflare Stream driver) |

Outputs that are not files (palette, thumbhash, scenes, a transcript's words) are written to `metadata`: the media's for a rendition without files, its main file's for a rendition with files (index 0 for a group).

### Metadata keys

Metadata keys are snake_case and come from one list, so every prober and transcoder writes the same keys. Drivers map their own names to it (Eclair's `frameRate` → `frame_rate`, `pageCount` → `pages_count`). A key outside the list is stored converted to snake_case.

| Key | Notes |
|---|---|
| `kind` | `image`, `video`, `audio`, `document`, `font` or `other`: the probe's kind, shared by every prober and transcoder |
| `mime`, `extension`, `size`, `checksum` | Detected from the content; `checksum` is a SHA-256 in hex |
| `filename` | The name the file was ingested with |
| `width`, `height`, `rotation` | Pixels, the displayed (upright) size; a video's rotation in degrees, clockwise (0, 90, 180, 270) |
| `orientation` | An image's EXIF orientation, from 1 to 8, when it declares one. Originals are stored as they are: renderers (`laravel-image`) turn them upright |
| `duration`, `frame_rate`, `bitrate` | Seconds, frames per second, bits per second |
| `video_codec`, `audio_codec`, `audio_channels` | |
| `pages_count` | Documents |
| `font_names` | Fonts |
| `animated` | Animated GIF and WebP |
| `exif` | A summary, never GPS data |
| `palette`, `scenes` | Metadata outputs of renditions |
| `thumbhash` | An image placeholder, a [ThumbHash](https://evanw.github.io/thumbhash/) in base64, of its first frame, upright: the local prober's for an image other than SVG, the `ffmpeg` driver's for the image files it writes ([decisions/0013](decisions/0013-media-resolver-for-laravel-image.md)) |
| `locale` | Caption tracks |

`mediatheque:upgrade` maps the 1.x names to these keys (`font_family_name` → `font_names`, `colors` → `palette`).

### Pipelines and jobs

A **rendition** is a declarative description of one output: an operation, its options, the rendition it reads from, and a condition. A **pipeline** is a named set of renditions, run by one transcoder. Each type has a default pipeline.

A **pipeline run** (`pipelines` table) records one execution:

| Attribute | Notes |
|---|---|
| `name`, `pipelinable` | The pipeline and the media it runs on |
| `transcoder`, `external_id` | The driver and its job id (an Eclair job, a MediaConvert job) |
| `definition` | JSON snapshot of the renditions, the output source, the run's path prefix and the media's visibility; never `serialize()` |
| `status` | `PipelineStatus` enum: `pending`, `running`, `completed`, `failed`, `canceled` |
| `progress` | Null until one of its renditions is measured or ended; the recorder stores it with each call, for queries. `progress()` computes it ([Progress](#progress)) |
| `attempts`, `error` (`{code, message}`), `started_at`, `ended_at` | |

Each rendition gets a **pipeline job** (`pipelines_jobs`) with its own `status` (`queued`, `running`, `completed`, `failed`, `skipped`, `canceled`), `progress` (null until measured), `attempts`, `error`, and `queued_at`, `started_at` and `ended_at`. `queued_at` is when the rendition can run: when its run is created, or when the rendition it reads from completes ([decisions/0017](decisions/0017-monitoring-dashboard.md)). Statuses only move forward ([Recorder](#transcoders)). A failed job fails the jobs that read from it (`dependency_failed`); a skipped job skips them; independent jobs continue. `skipped` is a normal outcome: a condition did not match the probed input (an audio-only video, a short clip).

A run is never retried in place: retrying failed renditions creates a new run, with its own driver job ([decisions/0012](decisions/0012-pipeline-runs-recorder-and-probe.md)). Queue retries of the `ffmpeg` driver's jobs stay within their run and count in `attempts`.

### Attachments and usages

Application models attach media through the `HasMedias` trait, on the 1.x `medias_pivot` table (`media_id`, `morphable`, `handle`, `order`). The pivot is also the **usage graph** ([decisions/0020](decisions/0020-attachments-by-handle-and-usages.md)):

```php
$post->attachMedia($media, 'cover');                          // after the media already under the handle
$post->syncMedias(['cover' => $cover, 'gallery' => [$a, $b]]); // replaces these handles only
$post->detachMedia($media, 'cover');
$post->media('cover');                // Media|null
$post->medias('gallery');             // Collection<Media>, from the loaded relation
$post->medias();                      // the MorphToMany relation, with handle and order on its pivot

$media->usages();                     // Collection<MediaUsage>: model, handle, order
$media->isUsed();
```

- Writes go by handle: `syncMedias()` leaves the handles it doesn't name, so a form and the sync of JSON references (`laravel-json-data`'s cast, under `json` for every JSON column of a model, [decisions/0021](decisions/0021-json-references-handle.md)) don't undo each other. Media are given as models or as their keys.
- A media that is in use can't be deleted unless the deletion is forced: `delete()` throws `MediaInUse`, `forceDelete()` deletes it and its usages.
- A deleted model no longer uses its media; a soft-deleted one keeps them.

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
4. a file that matches no type is rejected, instead of becoming a `document`;
5. mimes whose files a server could execute or a browser render as an active document (HTML, XML, scripts, executables) are never stored, even when a custom type's patterns accept them.

`Mediatheque::type('unknown')` throws. Custom types register in the config or with `Mediatheque::extendType()`.

New in 2.0: the `captions` type (WebVTT and SRT, stored as VTT), with a `locale` and an optional `derived_from` pointing to the media it captions.

## Ingest

Every way in ends in the same `ingest()` step, which validates, stores the original and creates the media.

```php
$media = Mediatheque::ingest($request->file('file'));
$media = Mediatheque::ingest(Source::disk('s3', 'uploads/abc.mov'));
$media = Mediatheque::ingest(Source::url('https://example.com/video.mp4'));
$media = Mediatheque::ingest($file, visibility: Visibility::Private, pipeline: 'video', name: 'Episode 1');
$media = Mediatheque::ingest($file, attributes: ['tenant_id' => $tenant->id]);   // the application's columns
```

`attributes` sets the application's own columns on the media before its path is built, so the path formatter can use them ([decisions/0015](decisions/0015-new-install-schema-attributes-and-uploads.md)).

| Source | Notes |
|---|---|
| `UploadedFile`, local path, stream | Copied to the media's source |
| A file already on a disk (`Source::disk()`) | Moved or copied without going through PHP when the disk allows it. This is how files that the browser uploaded directly to storage are ingested |
| A URL (`Source::url()`) | Downloaded in a queued job, with an SSRF guard (public addresses only, size and time limits, redirects checked) |

Ingest steps:

1. Detect the type from the content; check the type's maximum size and allowed mimes.
2. Sanitize SVG. For JPEG, PNG, WebP, TIFF, HEIF and AVIF images, remove the location from the stored original by default (`ingest.images.strip_location`): the EXIF GPS entries and the XMP `GPS…` properties are blanked in place, without re-encoding, so the orientation, the camera and the pixels stay ([decisions/0006](decisions/0006-ingest-and-uploads.md)). An image on a disk is downloaded for it.
3. Build the path with the path formatter, which receives the media (`{media.uid}`, or an application attribute such as a tenant id).
4. Store the original, create the media (`pending`) and dispatch `MediaCreated`.
5. Queue the probe, then the type's pipeline.

Ingest runs in a transaction: a failure leaves neither a stored file nor a row behind.

### Files uploaded directly to storage

The package takes no uploads ([decisions/0018](decisions/0018-ingest-only.md)). Large files go from the browser to an S3-compatible disk through the application's routes, or Panneau 5's direct uploads, which sign each request and track each upload. Once the file is on the disk, the application ingests it with `Source::disk()`, and chooses the media's attributes, visibility and pipeline from its own context. An application that needs TUS runs its own server and ingests what it receives the same way.

## Probe

A `Prober` reads a file's technical metadata: kind, mime, dimensions, rotation, duration, frame rate, bitrate, codecs, audio channels, page count, font names, animation.

- The local prober uses ffprobe, Imagick or `getimagesize`, and otfinfo.
- Eclair's prober uses its `probe` endpoint.
- The probe runs in a queued job.

The contract is asynchronous ([decisions/0012](decisions/0012-pipeline-runs-recorder-and-probe.md)): a prober returns the metadata, a pending outcome with its external id when the probe takes longer (Eclair answers `202` with a job), or an error. The metadata always has a `kind`.

```php
interface Prober
{
    public function probe(File $file): ProbeOutcome;   // ProbeOutcome::done($metadata), ::pending($externalId) or ::failed($error)
}
```

Every probe is recorded as a run of the `probe` pipeline (one rendition, operation `probe`, no files) and completes through the recorder, like any run, so its duration, attempts and error are recorded ([decisions/0017](decisions/0017-monitoring-dashboard.md)). A prober that answers at once completes it in the probe job; a pending one records its external id, and its driver completes it. A retried probe job reuses its run. The media stays `pending` until then. `MediaProbed` is dispatched when the results are stored, and the pipeline starts then, never before: the one ingest chose (`pipeline:`), or the type's. A failed probe fails the media.

Probe results fill the media's `metadata`, with the [canonical keys](#metadata-keys), and the original's `width`, `height`, `duration` and `bitrate`.

## Renditions and pipelines

Renditions are declared in the config, or in PHP for applications that prefer classes. Operation names and options follow Eclair's vocabulary (`video.transcode`, `video.thumbnails`, `video.hls`, `audio.waveform`…), so a pipeline means the same thing on every transcoder.

```php
'pipelines' => [
    'video' => [
        'transcoder' => env('MEDIATHEQUE_TRANSCODER', 'ffmpeg'),
        'source' => null,                     // the original's source by default
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
    'image' => [
        'transcoder' => 'ffmpeg',
        'renditions' => [
            // A JPEG of the images not every browser shows; others run nothing
            'web' => ['op' => 'image.convert', 'format' => 'jpeg', 'when' => ['mime' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'image/avif']]],
        ],
    ],
],
```

A pipeline's renditions are written to its `source`, the original's source by default. A rendition can set:

- `from`: another rendition it reads from;
- `when`: `kind` (the probe's kinds: `image`, `video`, `audio`, `document`, `font`, `other`, never a mediatheque type), `mime`, `min_duration`, `max_duration`. A rendition whose condition doesn't match is `skipped`;
- `keep: false`: an intermediate output, recorded as `completed` with no file. Renditions of the same run read it;
- `queue`: the queue of the `ffmpeg` driver's job. Remote drivers ignore it.

The rules are in [decisions/0011](decisions/0011-renditions-on-remote-transcoders.md); the config reader and `mediatheque:doctor` refuse a `when.kind` outside the list.

Renditions are **idempotent**. Running a pipeline again only produces the renditions that are missing, failed, or whose definition changed (`definition_hash`). A run's renditions are **closed under `from`**: if a rendition in the run reads from another one, that one is in the run too and is produced again, even if it exists. An application can also ask for one rendition on demand:

```php
$media->ensureRendition('vertical');          // queued if missing
Mediatheque::runPipeline($media, 'video');    // the whole pipeline again
```

Applications, commands and the monitoring dashboard act on runs through the same entry point ([decisions/0017](decisions/0017-monitoring-dashboard.md)):

```php
Mediatheque::retry($run);         // a new run of its failed and canceled renditions, forced; null if there is nothing to retry
Mediatheque::cancel($run);        // through the transcoder's cancel capability; false if it can't
Mediatheque::refresh($run);       // through the transcoder's refresh capability; false if it can't
Mediatheque::probeAgain($media);  // a media whose probe failed goes back to pending; false for any other
```

`retry()` returns null for a run that hasn't ended, and while the media has a run of the same pipeline in progress, so retrying twice doesn't create two runs. A probe that runs in the application's queue is canceled through the recorder; one pending with its driver isn't.

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

    /** Optional abilities: progress (pushed or polled), cancel, refresh, probe, remote renditions. */
    public function capabilities(): Capabilities;

    /** Start the run. Returns the driver's job id, if it has one. */
    public function submit(PipelineRun $run): ?string;

    public function cancel(PipelineRun $run): void;

    /** With the refresh capability: fetch the run's state and report it through the recorder. */
    public function refresh(PipelineRun $run): void;
}

interface Recorder
{
    public function started(PipelineJob $job): void;
    public function progress(PipelineJob $job, float $progress): void;
    public function completed(PipelineJob $job, Outputs $outputs): void;  // files and metadata outputs
    public function skipped(PipelineJob $job, string $reason): void;
    public function failed(PipelineJob $job, TranscoderError $error): void;
    public function canceled(PipelineJob $job): void;
}
```

The rules below are in [decisions/0012](decisions/0012-pipeline-runs-recorder-and-probe.md).

- **Idempotent and forward only.** A duplicated webhook or a retried queue job changes nothing the second time. Statuses only move forward (`queued` → `running` → `completed`, `failed`, `skipped` or `canceled`): a call that would move a rendition back, or change a final status, is ignored, and progress never goes down. A driver that receives the whole job on every webhook reports every rendition each time, in whatever order deliveries arrive.
- **Dependencies.** A failed rendition fails the renditions that read from it (`dependency_failed`), a skipped one skips them, a canceled one cancels them; independent renditions continue.
- **End of a run.** A run ends when every rendition has a final status: `failed` if one failed, else `canceled` if one was canceled, else `completed` (skipped renditions are a normal outcome). After a run that includes its main rendition, the media is `ready` if the main rendition completed or was skipped (it plays its original), `failed` otherwise; a `ready` media that still has a file for it stays `ready` when a rerun fails. The main rendition is the first one with the `main` role (the default role) that keeps its output. A media whose main rendition's condition doesn't match its probe is `ready` once probed, and a pipeline none of whose kept renditions match the probe doesn't run ([decisions/0014](decisions/0014-run-outcomes-and-model-contracts.md)).
- **Progress** is a number from 0 to 1, or null while nothing measured it. Reporting progress moves a queued rendition to `running`, and completing it sets it to 1. Each time a rendition's progress passes a multiple of `events.progress_step`, the recorder dispatches `RenditionProgressed` ([Progress](#progress)).
- **Errors.** `TranscoderError` has a `code` from the `TranscoderErrorCode` enum (`input_unreadable`, `unsupported_input`, `timeout`, `limit_exceeded`, `operation_failed`, `storage_failed`, `provider_failed`, `dependency_failed`, the same as Eclair's) and a message for humans. An unknown code is read as `operation_failed`.
- **Refresh.** `mediatheque:refresh`, scheduled by the application, calls `refresh()` for runs that have been running too long (a lost webhook) and for every running run of a driver that reports progress only by polling. The MediaConvert poller is this command.
- **Retries.** A webhook for a run that has ended changes nothing. Retrying creates a new run.
- **Outputs.** The run tells the driver the output source, a path prefix for the run (`run_path_format`) and the media's visibility. Remote drivers write under the prefix with their own file names and apply the visibility to what they write; the file records the path the driver reports. Only the `ffmpeg` driver also builds each file's path with `file_path_format`.

### Progress

A rendition's progress is null until something measures it ([decisions/0023](decisions/0023-progress-of-runs.md)). Null is not 0: a transcoder that reports no progress (`ProgressReporting::None`, such as Eclair), ffmpeg when the input's duration is unknown, and operations that report nothing leave it null until the rendition ends. The `ffmpeg` driver reports `video.transcode` and `audio.transcode` from ffmpeg's `-progress` when the probe found the duration, and `video.thumbnails` thumbnail by thumbnail.

A run's progress is the mean of its renditions, weighted:

- a rendition that transcodes the whole input (`video.transcode`, `video.hls`, `video.reframe`, `audio.transcode`) weighs the input's duration in seconds; any other rendition (poster, thumbnails, waveform, probe) weighs one second. Without a duration, every rendition weighs the same;
- an ended rendition (completed, failed, skipped or canceled) is done;
- a rendition that nothing measured yet counts as not started, so a poster that ends first doesn't make the run look done, and the run's progress never goes down;
- the run is null until one of its renditions is measured or ended.

With a 98-second video, `h264` at 50 % and the poster done, a run of `h264`, `poster` and `thumbnails` is at (49 + 1) / 100 = 50 %.

`RenditionProgressed` carries the job, the rendition's progress and the run's. The recorder dispatches it each time the rendition's progress passes a multiple of `events.progress_step` (0.05 by default), once per call however many steps it passed: never for a call that changes nothing (a duplicated or late report), never for a status change, which has its own event.

| Driver | Runs | Completion | Package |
|---|---|---|---|
| `ffmpeg` | ffmpeg, ffprobe, Imagick and fonttools in the application's queue. Since 2.0.0-alpha.1: `video.transcode` (`h264`, `vp9`), `video.poster`, `video.thumbnails`, `audio.transcode` (`mp3`, `aac`), `audio.waveform`; `image.convert` (`jpeg`, `webp`, `png`, with Imagick) since #132 | Each rendition is a queued job; dependent renditions are dispatched when their input completes | This package |
| `eclair` | The whole run as one Eclair job (task graph, `when` conditions, outputs written to the application's storage) | Signed webhooks, deduplicated | [`laravel-eclair`](https://github.com/urbania-media/eclair/issues/13) |
| `mediaconvert` | AWS Elemental MediaConvert jobs for video renditions | EventBridge notifications through a webhook, or a scheduled poller. Never `sleep()` in a worker | This package, with `aws/aws-sdk-php` (suggested) |
| `cloudflare-stream` (2.x) | Upload to Cloudflare Stream; remote renditions | Webhook | Later, if a project needs it |

One transcoder runs a whole pipeline run. A pipeline whose renditions need two transcoders is split into two pipelines. At boot, `mediatheque:doctor` checks that each pipeline's transcoder supports its operations, and warns when a pipeline on a remote driver sets `queue`.

## Visibility and URLs

A media is `public` or `private`. Its files follow its visibility on their source (object ACL on S3-compatible disks). Transcoders write their outputs with the visibility of the media when their run started.

A media's visibility changes through `setVisibility()`, never through its column alone, which would leave its files as they are ([decisions/0007](decisions/0007-visibility-and-urls.md), [#119](https://github.com/folkloreinc/laravel-mediatheque/issues/119)):

```php
$media->setVisibility(Visibility::Private);                 // false when it is private already
Mediatheque::setVisibility($media, Visibility::Public);
```

- The media has its new visibility at once: `url()` gives temporary URLs of a media made private right away.
- `MediaVisibilityChanged` is dispatched with the previous and the new visibility, and the `ApplyVisibility` job applies the media's visibility to its stored files, the original and the renditions. It reads the visibility when it runs, so a later change wins, and it is safe to retry. A file its disk doesn't have any more is skipped; a file whose visibility can't be set fails the job.
- A run in progress writes its files with the visibility it started with. When the recorder records them, it queues the same job if the media's visibility changed since.

```php
$media->url();                                    // the main rendition, or the original
$media->url('h264');
$media->temporaryUrl('h264', now()->addMinutes(30));
$media->temporaryUrl(null, now()->addMinutes(30), download: 'clip.mp4');   // saves the file as clip.mp4
$media->file('h264')->url();
```

- **Public:** the disk's URL. A CDN in front of the disk is its `url` option (`AWS_URL` on S3 disks): a Spaces CDN endpoint (`https://media.nyc3.cdn.digitaloceanspaces.com`), a CloudFront or Cloudflare domain. Public files are then served by the CDN, and their paths never change, so it can cache them. The temporary URL of a private file is signed by the disk itself, at its origin: a CDN would cache it, or can't check its signature. A CDN that signs its own URLs comes with a `UrlSigner` ([#129](https://github.com/folkloreinc/laravel-mediatheque/issues/129)).
- **Private:** `url()` returns a temporary URL with the configured lifetime (`urls.temporary_ttl`). Disks that support temporary URLs (S3) sign them themselves. A local disk signs them through Laravel's `serve` option (`'serve' => true` in the disk's config); without it, a private file on a local disk has no URL. The package has no streaming route of its own.
- **Downloads:** a temporary URL can ask for the file to be saved rather than shown (`download: true`, or `download: 'clip.mp4'`), on the `UrlGenerator`, a file and a media. On a media, `true` is its name with the extension of the file it serves (`episode-1.mp4`), or the file's name when the media has none; on a file, the file's name. S3 and S3-compatible disks (DigitalOcean Spaces) answer it with `Content-Disposition: attachment`, the name in UTF-8 (`filename*`) and in ASCII (`filename`), as RFC 6266 describes. A local disk's signed route has no such option, so there the URL shows the file. A file's name is the uploaded file's for an original, and the name its transcoder wrote for a rendition (`h264.mp4`). A name given by the application must be a file name, without a directory or control characters ([#102](https://github.com/folkloreinc/laravel-mediatheque/issues/102)).
- **CDN signing** goes through a `UrlSigner` contract, for CDNs that sign their own URLs.
- **Private HLS** needs signed segments: the playlist is rewritten through a signed route, or the CDN signs by token. This is planned for the beta, with the HLS rendition.

### Images and `laravel-image`

Image renditions (sizes, crops, formats) are not stored by the mediatheque: `laravel-image` 2 renders them on the fly from the media's image, its original, or the `web` JPEG of a HEIC, HEIF or AVIF, which the `image` pipeline produces at ingest so every browser shows it ([decisions/0009](decisions/0009-package-boundaries.md)). The mediatheque provides the `media` resolver of `laravel-image` (its `SourceResolver` contract), registered when `laravel-image` is installed, so image URLs never expose storage paths. Video posters and thumbnails, which come out of a pipeline, are files of the media and go through `laravel-image` the same way.

The resolver turns a media (its contract), a `MediaReference`, `media://med_…`, a uid or a key with a handle (`med_…/poster`) into an `ImageSource`: the key, a version, the disk and path of the file, its metadata (`width`, `height`, `mime`, `orientation`, `thumbhash`), the media's `transform` and focal point for its image, and whether it is private. [decisions/0013](decisions/0013-media-resolver-for-laravel-image.md) has the rules, agreed with `laravel-image` ([its decision 0010](https://github.com/folkloreinc/laravel-image/blob/v2.x/docs/decisions/0010-media-resolver.md)):

- the version is the first 8 hex characters of the resolved file's `checksum`;
- `med_…` resolves to the image an `image` media plays (its `web` version, or its original) and the `thumbnail()` of other types; `med_…/{handle}` and `med_…/{handle}/{index}` to a file of the media (`med_…/thumbnails/2`); only image files resolve;
- a source is a Laravel disk name;
- deleted media don't resolve; pending and failed media resolve to the image files they have.

The edits of an image were settled with `laravel-image` 2 and Panneau 5 in [laravel-image#88](https://github.com/folkloreinc/laravel-image/issues/88) ([decisions/0022](decisions/0022-image-transform-and-orientation.md)):

- the media's `transform` (crop, rotation, flips, focal point, in pixels of and relative to the upright original) applies to the image of an `image` media, its original or its `web` version, which has the same upright size, not to a video's poster or thumbnails; `laravel-image` hashes it into the version of URLs, so editing a media gives new URLs;
- `orientation` is the image's EXIF orientation; originals are stored as they were uploaded, and `laravel-image` turns renderings upright;
- the dominant color is `palette[0]`;
- `laravel-image` clears a media's derivatives on `MediaVisibilityChanged` and `OriginalReplaced`.

## JSON resource

The package ships the canonical JSON shape of a media, `MediaResource`, and of its files, `FileResource` ([decisions/0010](decisions/0010-json-resource.md)). It has the meaning of Panneau 5's `MediaInfo` in snake_case, so laravel-panneau only renames its keys, and applications extend it to add their fields:

```json
{
  "id": "med_01JAB…",
  "type": "video",
  "name": "Episode 1",
  "status": "processing",
  "visibility": "public",
  "error": null,
  "processing": { "pipeline": "video", "progress": 0.42, "rendition": "h264" },
  "mime": "video/quicktime",
  "size": 7340032,
  "width": 1920,
  "height": 1080,
  "duration": 2851.2,
  "url": "https://cdn.example.com/…/h264.mp4",
  "thumbnail_url": "https://cdn.example.com/…/poster.jpg",
  "transform": null,
  "metadata": { "kind": "video", "filename": "episode-1.mov", "frame_rate": 25, "…": "…" },
  "files": {
    "original": { "handle": "original", "role": "original", "name": "episode-1.mov", "url": "…", "mime": "video/quicktime", "size": 7340032, "width": 1920, "height": 1080, "duration": 2851.2 },
    "h264": { "handle": "h264", "role": "main", "name": "h264.mp4", "url": "…", "mime": "video/mp4", "size": 2107637, "width": 1920, "height": 1080, "duration": 2851.2, "bitrate": 5913000 },
    "thumbnails": [ { "handle": "thumbnails", "index": 0, "role": "thumbnail", "name": "thumbnails-0.jpg", "url": "…", "mime": "image/jpeg", "size": 20480, "width": 640, "height": 360 } ]
  },
  "created_at": "2026-10-21T14:00:00Z",
  "updated_at": "2026-10-21T14:00:00Z"
}
```

- `mime` and `size` are the original's; `width`, `height` and `duration` come from the metadata. `url` is the file the media plays; `thumbnail_url` is that file for an image, and `thumbnail()`'s for other types (override `thumbnailUrl()` for a `laravel-image` preset).
- `error` is `$media->failure()` while the media is `failed`; `processing` is `$media->processing()` while it is `pending` or `processing`. Each costs a query, for those media only.
- `files` is an object keyed by handle, the original first; a group is a list. A file has `index` in a group only, and `width`, `height`, `duration`, `bitrate` and `parts_count` when known. A URL the disk can't give is null.
- The shapes are in [`tests/fixtures/json/`](../tests/fixtures/json/): `media-video-ready`, `media-video-processing`, `media-video-failed` and `media-image`. There is no legacy shape: `laravel-folklore` builds its 1.x `MediaResource` from the contracts.

Runs and their renditions have their own resources, for monitoring ([decisions/0017](decisions/0017-monitoring-dashboard.md)): `PipelineRunResource` and `PipelineJobResource`. A run gives its media in short (`id`, `type`, `name`, `status`) and its renditions in the order of its definition. Times are ISO 8601 in UTC; `duration` and a rendition's `wait` in the queue are in seconds. The shapes are in [`tests/fixtures/json/`](../tests/fixtures/json/):

```json
{
  "id": "run_01JAB…",
  "pipeline": "video",
  "status": "failed",
  "transcoder": "ffmpeg",
  "external_id": null,
  "progress": 1,
  "error": { "code": "timeout", "message": "The poster took too long." },
  "media": { "id": "med_01JAB…", "type": "video", "name": "Episode 1", "status": "ready" },
  "renditions": [
    { "rendition": "h264", "operation": "video.transcode", "from": null, "status": "completed", "progress": 1, "attempts": 1, "error": null, "reason": null,
      "queued_at": "2026-10-21T14:00:00Z", "started_at": "2026-10-21T14:00:05Z", "ended_at": "2026-10-21T14:02:05Z", "wait": 5, "duration": 120 }
  ],
  "created_at": "2026-10-21T14:00:00Z",
  "started_at": "2026-10-21T14:00:05Z",
  "ended_at": "2026-10-21T14:02:06Z",
  "duration": 121
}
```

A progress that nothing measured is `null`, not `0`, in both resources.

## HTTP

Routes are off by default ([decisions/0008](decisions/0008-secure-http.md)). The package has no upload routes ([decisions/0018](decisions/0018-ingest-only.md)): the application's routes, or Panneau's, take files and call `ingest()`. Its only route is the monitoring dashboard's, `Route::mediathequeDashboard()`, which the application registers inside its own middleware ([decisions/0017](decisions/0017-monitoring-dashboard.md)).

Private files on local disks are served by Laravel's `serve` option, not by a route of the package ([Visibility and URLs](#visibility-and-urls)).

## Events

| Event | When |
|---|---|
| `MediaCreated`, `MediaUpdated`, `MediaDeleted`, `MediaRestored` | Model lifecycle |
| `MediaProbed` | Probe results stored |
| `PipelineStarted`, `PipelineCompleted`, `PipelineFailed`, `PipelineCanceled` | Pipeline run |
| `RenditionCompleted`, `RenditionFailed`, `RenditionSkipped`, `RenditionCanceled` | Each rendition. Drivers that report from webhook snapshots may dispatch them some time after the fact |
| `RenditionProgressed` | A rendition's progress passed a multiple of `events.progress_step`: the job, the rendition's progress and the run's ([Progress](#progress)) |
| `MediaReady` | Every rendition of the default pipeline ended, at least the main one completed |
| `MediaFailed` | The probe failed, or the main rendition did not complete |
| `MediaVisibilityChanged` | `setVisibility()` changed a media's visibility: the media, the previous and the new visibility. Its files follow in a queued job |
| `OriginalReplaced` | The original was replaced: the media, the previous and the new checksum ([decisions/0019](decisions/0019-replace-original.md)) |
| `IngestRejected` | Ingest, or the replacement of an original, refused a file: its reason, message and file name. Nothing was stored |

Events carry contracts, not concrete classes, so they work with the application's models.

### Broadcasting progress

The package broadcasts nothing: clients poll `processing()` or the resources ([decisions/0023](decisions/0023-progress-of-runs.md)). An application that wants progress live (Reverb, Pusher, Ably) broadcasts `RenditionProgressed` from its own listener, on channels it authorizes, since the package doesn't know who may see a media:

```php
use Folklore\Mediatheque\Events\RenditionProgressed;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class MediaProgressed implements ShouldBroadcastNow
{
    public function __construct(public string $media, public ?float $progress, public string $rendition, private string $team) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('teams.'.$this->team.'.media'); // authorized in routes/channels.php
    }
}

Event::listen(function (RenditionProgressed $event) {
    $media = $event->media();
    broadcast(new MediaProgressed($media->uid(), $event->runProgress, $event->rendition(), $media->team_uid)); // an application column
});
```

The listener runs where the recorder runs (a queue worker, a webhook request), after the change is committed. Keep it cheap, or queue it.

## Testing

```php
Mediatheque::fake();

$media = Mediatheque::ingest(UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'));

Mediatheque::assertIngested(fn (Media $m) => $m->type() === 'video');
Mediatheque::assertPipelineRan($media, 'video');
Mediatheque::assertRendition($media, 'h264', JobStatus::Completed);

Mediatheque::fake()->failRendition('h264');                 // or skipRendition(), failProbe()
Mediatheque::fake()->probeWith(['duration' => 12.5]);       // over the probe fixtures
Mediatheque::fake()->holdPipelines();                       // renditions stay queued until releasePipelines()
Mediatheque::fake()->progressRendition($media, 'h264', 0.42); // as a driver reports it, while held
Event::assertDispatched(RenditionProgressed::class);
```

The fake stores files on fake disks, returns probe fixtures, and completes renditions with placeholder files, without ffmpeg, a database or a queue worker. It dispatches the package's events, and reports renditions through an in-memory recorder that passes the same contract tests as the package's recorder (`tests/Contracts/`). The fake binds the `Mediatheque`, `Ingestor`, `Recorder`, `Prober` and `UrlGenerator` contracts.

## Extension

- **Models.** `Media`, `File`, `PipelineRun` and `PipelineJob` are bound to contracts and set in the config (`models.media`…). Applications extend them to add columns, scopes and relations. The contracts are methods; the Eloquent relations have other names (`mediaFiles()`, `pipelineJobs()`…, [decisions/0014](decisions/0014-run-outcomes-and-model-contracts.md)).
- **Migrations.** `mediatheque:install` publishes the migrations; the package does not load them itself. The application owns its schema and can add its own columns (a tenant id, for example) in the same migration ([decisions/0015](decisions/0015-new-install-schema-attributes-and-uploads.md)).
- **Global scopes.** Package workers load models through the morph map and without global scopes, so a tenant scope that depends on the current request does not hide media from queued jobs.
- **Path formatter.** Receives the media and the file: `{media.uid}`, `{media.tenant_id}` (a column's raw value, or the model's accessor when no column has that name, such as `{media.tenant_uid}`), `{type}`, `{handle}`, `{index}` (`-2` in a group, empty otherwise), `{extension}`, `{date(Y/m)}`. It builds originals with `file_path_format`, and each run's prefix with `run_path_format` (the media's placeholders and `{run}`). Every driver writes a run's files under its prefix: remote transcoders with their own file names, the `ffmpeg` driver with the last segment of `file_path_format`. A placeholder that names a missing attribute, or a value that would leave its directory, throws. Paths are recorded, so consumers never rebuild them.
- **Types, renditions, transcoders, probers, URL signers and sources** register through the config or `Mediatheque::extend*()`.

## Commands

`mediatheque:install`, `mediatheque:status`, `mediatheque:prune` and `mediatheque:upgrade` exist today; the other commands are planned ([roadmap](roadmap.md)).

| Command | Purpose |
|---|---|
| `mediatheque:install` | Publish the config and migrations, check the environment |
| `mediatheque:doctor` (to come) | Binaries and versions, disks and visibility, queue `retry_after` against job timeouts, transcoders against pipelines (operations, `when.kind`, `queue` on remote drivers), `mediatheque:refresh` scheduled when a driver needs it |
| `mediatheque:install --upgrade` | Publish the migration that adds the 2.0 columns to the 1.x tables instead of the one that creates them |
| `mediatheque:upgrade` | Convert a 1.x database, in resumable batches (see [upgrade.md](upgrade.md), [decisions/0024](decisions/0024-upgrade-conversion-rules.md)); `--check` lists what it would remove or can't convert, `--config` rewrites a 1.x config, `--cleanup` drops the EAV table |
| `mediatheque:run-pipeline` (to come) | Run a pipeline on media, by type or uid |
| `mediatheque:refresh` (to come) | Scheduled: fetch the state of runs that have been running too long, and the progress of drivers that only report it by polling |
| `mediatheque:prune` | Scheduled daily: force-delete media soft-deleted more than `prune.after_days` ago (30), and delete runs that ended more than `prune.runs_after_days` ago (7), keeping each media's last run of each pipeline. `--dry-run` lists what it would delete |
| `mediatheque:install --dashboard` | Also publish and register the provider of the dashboard's gates |
| `mediatheque:status` | Media by status, runs in progress and stuck, what ended in the last hours, and the latest failures (`--hours`, 24 by default) |

## Monitoring

`Folklore\Mediatheque\Monitoring\Monitoring` is the read model that the monitoring dashboard, `mediatheque:status` and an application's own views read ([decisions/0017](decisions/0017-monitoring-dashboard.md)). It returns readonly value objects:

| Method | Returns |
|---|---|
| `overview(Period)` | Media by status, runs in progress, stuck runs (not ended `monitoring.stuck_after` seconds after they started), runs that ended in the period by status, and the oldest queued rendition |
| `runs(RunFilters, cursor, limit)` | Runs, newest first, with their jobs and media. The filters are status, pipeline, transcoder, media type, error code, media uid, period, and stuck. Pages are cursor-paginated |
| `failures(Period)` | Failed renditions grouped by error code, pipeline, transcoder and rendition, with a count and the last message |
| `durations(Period)` | How long each rendition takes and waits in the queue (median, p95), by pipeline, rendition and transcoder, over at most `monitoring.max_rows` renditions |
| `throughput(Period)` | Runs ended per hour by status, and bytes ingested and produced |

The queries ignore the application's global scopes, as the package's workers do. An application narrows them with `Mediatheque::monitoringScope()`, which receives a query of media; runs and renditions are narrowed through their media:

```php
Mediatheque::monitoringScope(fn (Builder $query, string $model) => $query->where('tenant_id', $tenant->id));
```

### The dashboard

A precompiled React SPA, shipped in `dist/` and built from `dashboard/`, on `@panneau/ui` at an exact version ([decisions/0017](decisions/0017-monitoring-dashboard.md)). It shows an overview (media by status, runs in progress and stuck, what ended in the period, storage, runs per hour, durations), runs with their filters and details, and failures, and acts on runs. It polls: every 5 seconds while something is in progress, every 30 otherwise.

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::mediathequeDashboard();              // /mediatheque, or a prefix of your own
});
```

- **Gates.** `viewMediathequeDashboard` covers every route, and `operateMediathequeDashboard` also covers the actions (retry, cancel, refresh, probe again), which send the page's CSRF token. Until the application defines a gate, it is open in the `local` environment and closed everywhere else. `mediatheque:install --dashboard` publishes `app/Providers/MediathequeServiceProvider.php`, which defines both, and registers it.
- **Endpoints** under `api/` serve the page only, and change with it: they are not a versioned API. They find runs and media within `monitoringScope()`, actions included.
- **Building it.** `npm ci && npm run build` in `dashboard/` writes `dist/`, which is committed; CI fails when it doesn't match the sources. `dashboard/` is left out of the Composer archive.

## Deletion and replacement

- Deleting a media soft-deletes it. Its files stay until `mediatheque:prune` force-deletes it after `prune.after_days` ([#121](https://github.com/folkloreinc/laravel-mediatheque/issues/121)). Force deletion removes its rows, files, pipeline runs and their renditions, and pivot rows, then deletes its stored files, the original and the renditions, in a queued job (`DeleteStoredFiles`) once committed.
- The application schedules the command:

```php
// routes/console.php
Schedule::command('mediatheque:prune')->daily();
```

- `mediatheque:prune` works in batches, so a large table isn't locked, and running it again deletes nothing more. A setting set to null leaves media or runs. Files on a disk that no row references (orphans) are not looked for: listing a bucket is expensive. A later `--orphans` option can, if a project needs it.
- A media in use can't be deleted unless the deletion is forced: `delete()` throws `MediaInUse` ([decisions/0020](decisions/0020-attachments-by-handle-and-usages.md)).
- `$media->replaceOriginal($source)`, or `Mediatheque::replaceOriginal($media, $source)`, replaces the original and keeps the media ([decisions/0019](decisions/0019-replace-original.md)):

```php
Mediatheque::replaceOriginal($media, $request->file('file'));
Mediatheque::replaceOriginal($media, Source::disk('s3', 'uploads/abc.mov'), new IngestOptions(name: 'Episode 1, new cut'));
$media->replaceOriginal($file, allowTypeChange: true);     // an image replaced by a video
```

  - The file is validated as ingest validates it. A file ingest would refuse throws `FileRejected`, and so does a file of another type unless `allowTypeChange` is set (`RejectionReason::TypeChanged`). Nothing changes then.
  - The media keeps its `uid`, visibility, data, the application's attributes, its usages, and its name unless the options give one. Of the options, only `name`, `pipeline` and `source` are read; the source is the current original's disk by default.
  - Its original, mime, size, checksum and metadata are the new file's. The new original is stored at a path of its own (`original-{version}.mp4`).
  - The renditions are removed, and their files and the previous original are deleted in a queued job once the new original is stored. The runs that haven't ended are canceled, through their transcoder when it can; their late results are ignored, and the media doesn't fail.
  - The media goes back to `pending`: it is probed, then its pipeline runs again. `OriginalReplaced` carries the previous and the new checksum, so `laravel-image` URLs change.
  - Replacing an original with the same file again changes nothing.

## Configuration

The 1.x keys keep their meaning where the concept is unchanged (`table_prefix`, `source`, `file_path_format`, `types`, `pipelines`, `jobs`). `routes` is gone: the package has no routes but the dashboard's, which the application registers. A source is a Laravel disk name, so 2.0 has no `sources` key: a 1.x config is read with each source as a disk of the same name, and `mediatheque:upgrade --config` prints the disks to add ([decisions/0013](decisions/0013-media-resolver-for-laravel-image.md), [0024](decisions/0024-upgrade-conversion-rules.md)). New keys: `models`, `ingest`, `urls`, `transcoders`, `probe`, `prune`, `events`, `monitoring`, `run_path_format`.

The package merges its defaults deeply with the application's file, so a published config only needs its overrides. Lists (`mimes`) and a pipeline's `renditions` replace the defaults as a whole, and `false` removes a default type or pipeline. Every value is validated when it is read, with the key that is wrong. The 1.x shapes of `types` and `pipelines` are read with deprecation warnings (see [upgrade.md](upgrade.md)).

## Package layout

```
src/                                  PSR-4: Folklore\Mediatheque\
  Contracts/                          Public contracts: Mediatheque, Ingestor, Prober, Transcoder, Recorder,
                                      UrlGenerator, UrlSigner; Models/ (Media, File, PipelineRun, PipelineJob)
  Models/                             Media, File, PipelineRun, PipelineJob
  Enums/                              MediaStatus, Visibility, PipelineStatus, JobStatus, ProbeKind,
                                      TranscoderErrorCode, ProgressReporting, RejectionReason
  Events/
  Exceptions/
  Facades/                            Mediatheque
  Metadata/                           Canonical keys
  Ingest/                             Sources, validation, sanitizers, path formatter
  Probe/                              Prober contract implementations
  Pipelines/                          Renditions, runner, recorder, progress
  Transcoders/Ffmpeg/                 (MediaConvert/ to come)
  Images/                             The `media` resolver of laravel-image 2
  Casts/
  Jobs/                               Probe and rendition jobs, file visibility and deletion
  Urls/                               UrlGenerator, signers, visibility changes
  References/                         media:// and medias:// parsing and resolution
  Support/                            Uid, MediaUsage
  Http/                               JSON resources
  Concerns/                           HasMedias
  Compat/                             1.x compatibility layer (deprecated): model methods, type factory, bindings, config
  Upgrade/                            The conversion of a 1.x database: mediatheque:upgrade
  Testing/                            MediathequeFake, and the in-memory models, recorder, prober and URLs it uses
  Monitoring/                         The read model: overview, runs, failures, durations, throughput
  Dashboard/                          Route::mediathequeDashboard(), its gates, page and JSON endpoints
  Console/
config/mediatheque.php
database/migrations/
dashboard/                            The dashboard's sources (React, TypeScript, Vite)
dist/                                 The built dashboard, committed
stubs/                                The provider of the dashboard's gates
```

## Dependencies

- Required: `illuminate/*` components the code uses (declared), `symfony/mime`, `symfony/process`, an SVG sanitizer.
- The ffmpeg driver builds its commands with `symfony/process`, without `php-ffmpeg` or `php-ffmpeg-video-streaming`.
- Suggested: `ext-imagick`, `aws/aws-sdk-php` (MediaConvert), `league/flysystem-aws-s3-v3` (S3 disks), `folklore/laravel-eclair`, `folklore/laravel-image`.
- Removed: Guzzle, `ramsey/uuid`, `contao/imagine-svg`.

## Supported versions

PHP 8.3+ and Laravel 12+ ([decisions/0001](decisions/0001-supported-versions.md)).

The migrations and the test suite run on SQLite, MySQL 8.4 and PostgreSQL 17. On PostgreSQL, the JSON columns are `jsonb`; the other databases keep `json`.
