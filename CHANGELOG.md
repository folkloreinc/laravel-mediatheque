# Changelog

All notable changes to `folklore/laravel-mediatheque` 2.x are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). The changes of 1.x are in [the `v1.1` changelog](https://github.com/folkloreinc/laravel-mediatheque/blob/v1.1/CHANGELOG.md).

2.0 is released as pre-releases first (`2.0.0-alpha.N`, then beta and RC). Until the release candidate, a pre-release can break the previous one: each pre-release lists its breaking changes first, under **Breaking changes**. [`docs/upgrade.md`](docs/upgrade.md) covers the upgrade from 1.x.

## [Unreleased]

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

[Unreleased]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.2...v2.x
[2.0.0-alpha.2]: https://github.com/folkloreinc/laravel-mediatheque/compare/v2.0.0-alpha.1...v2.0.0-alpha.2
[2.0.0-alpha.1]: https://github.com/folkloreinc/laravel-mediatheque/compare/v1.1.3...v2.0.0-alpha.1
