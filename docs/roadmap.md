# Roadmap of 2.0

2.0 is built on the `v2.x` branch in slices named by theme. Pre-releases (`v2.0.0-alpha.N`, then beta and RC) are tagged when a part is ready, and each item says the release that shipped it; what each release contains is in the [CHANGELOG](../CHANGELOG.md). The pilot project, Niche, requires `^2.0@alpha` and pulls the slices from its own needs. 1.x stays maintained on `v1.1` for fixes and security only.

Index: [#1](https://github.com/folkloreinc/laravel-mediatheque/issues/1). Each item becomes a sub-issue of it when its work starts. The kickoff was [#23](https://github.com/folkloreinc/laravel-mediatheque/issues/23) (closed).

## Rules

- **Contracts first.** Each public contract is written with its tests before its implementation, and reviewed against its consumers (`laravel-image`, `laravel-eclair`, Panneau 5, `laravel-folklore` 2) before the release that ships it.
- **Each pre-release is usable end to end** for what it contains, with docs. Nothing half-done is tagged.
- Until the release candidate, an alpha can break the previous one. Breaking changes between alphas are listed in the CHANGELOG.
- Releases are tagged from a green CI on `v2.x`.

## Kickoff (done)

- [x] `v2.x` branch, created from `v1.1`
- [x] `docs/`: current state, architecture, decisions, this roadmap, upgrade guide
- [x] Tooling: PSR-4, PHP 8.3+, Laravel 12+, CI matrix (PHP 8.3 to 8.5 × Laravel 12 and 13), Pint, Larastan at the maximum level without a baseline ([#28](https://github.com/folkloreinc/laravel-mediatheque/issues/28))
- [x] Remove the 1.x code from `v2.x` and start the new layout; keep 1.x fixtures for the compatibility tests ([#29](https://github.com/folkloreinc/laravel-mediatheque/issues/29))
- [x] Release process: changelog, `v2.0.0-alpha.N` tags from green CI ([#30](https://github.com/folkloreinc/laravel-mediatheque/issues/30))
- [x] Contracts and their tests: `Media`, `File`, `uid` and references, `Ingest`, `Prober` (asynchronous), `Transcoder` and `Recorder`, `UrlGenerator`, `UrlSigner`, events, `Mediatheque::fake()` ([#31](https://github.com/folkloreinc/laravel-mediatheque/issues/31))
- [x] Review the `Transcoder` contract with Eclair ([urbania-media/eclair#13](https://github.com/urbania-media/eclair/issues/13), [review](https://github.com/folkloreinc/laravel-mediatheque/pull/24#issuecomment-6204765550)); answered in [decisions/0011](decisions/0011-renditions-on-remote-transcoders.md) and [0012](decisions/0012-pipeline-runs-recorder-and-probe.md)
- [x] Review the image resolver with `laravel-image` 2 ([laravel-image#59](https://github.com/folkloreinc/laravel-image/issues/59), [review](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071), #32)

## Ingest and play a video (2.0.0-alpha.1)

What the pilot's media library needed first, end to end. Released as [2.0.0-alpha.1](https://github.com/folkloreinc/laravel-mediatheque/releases/tag/v2.0.0-alpha.1) ([#58](https://github.com/folkloreinc/laravel-mediatheque/issues/58)).

- [x] Schema: `medias` with `uid`, `status`, `visibility`, `metadata`, soft deletes; `files` with `media_id`, `handle`, `index`, `role`, 64-bit `size`; pipeline runs and jobs with statuses ([#42](https://github.com/folkloreinc/laravel-mediatheque/issues/42))
- [x] Migrations owned by the application (`mediatheque:install` publishes them) ([#22](https://github.com/folkloreinc/laravel-mediatheque/issues/22), [#42](https://github.com/folkloreinc/laravel-mediatheque/issues/42))
- [x] `Mediatheque::ingest()` from an uploaded file and from a file on a disk, with content-based types, validation and SVG sanitizing ([#50](https://github.com/folkloreinc/laravel-mediatheque/issues/50))
- [x] Path formatter that receives the media, with a prefix per run ([#44](https://github.com/folkloreinc/laravel-mediatheque/issues/44))
- [x] Queued probe (ffprobe, Imagick) through the asynchronous `Prober` contract ([#48](https://github.com/folkloreinc/laravel-mediatheque/issues/48))
- [x] `ffmpeg` transcoder: web video rendition (`h264`), poster and thumbnails; audio `mp3` rendition and waveform ([#52](https://github.com/folkloreinc/laravel-mediatheque/issues/52))
- [x] `url()` and `temporaryUrl()`, public and private media. A private file on a local disk gets temporary URLs from Laravel's `serve` option ([#46](https://github.com/folkloreinc/laravel-mediatheque/issues/46))
- [x] `media://` and `medias://` references: `MediaReference`, `resolve()` and `resolveMany()` in one query per form ([#31](https://github.com/folkloreinc/laravel-mediatheque/issues/31), [#46](https://github.com/folkloreinc/laravel-mediatheque/issues/46))
- [x] Events (`MediaCreated`, `MediaProbed`, `Rendition*`, `Pipeline*`, `MediaReady`, `MediaFailed`) ([#50](https://github.com/folkloreinc/laravel-mediatheque/issues/50), [#52](https://github.com/folkloreinc/laravel-mediatheque/issues/52))
- [x] `Mediatheque::fake()` with assertions ([#31](https://github.com/folkloreinc/laravel-mediatheque/issues/31))

Uploads shipped here too, then left the package: presigned multipart uploads (alpha.1, [#54](https://github.com/folkloreinc/laravel-mediatheque/issues/54)) and Uppy 6's `signRequest` (alpha.3, [#65](https://github.com/folkloreinc/laravel-mediatheque/issues/65)) are Panneau 5's since 2.0.0-alpha.4 ([decisions/0018](decisions/0018-ingest-only.md), below).

## Images through `laravel-image` 2 (2.0.0-alpha.2)

- [x] The `media` resolver, so images ingested here get presets and signed URLs ([decisions/0013](decisions/0013-media-resolver-for-laravel-image.md), [#56](https://github.com/folkloreinc/laravel-mediatheque/issues/56), released with [#62](https://github.com/folkloreinc/laravel-mediatheque/issues/62))

## Ingest only, and monitoring (2.0.0-alpha.4)

- [x] Uploads leave the package: no upload routes, model or table; ingest from a file on a disk, as Panneau 5's direct uploads end ([decisions/0018](decisions/0018-ingest-only.md), [laravel-panneau#81](https://github.com/folkloreinc/laravel-panneau/issues/81), [#84](https://github.com/folkloreinc/laravel-mediatheque/pull/84))
- [x] Monitoring ([decisions/0017](decisions/0017-monitoring-dashboard.md), [#69](https://github.com/folkloreinc/laravel-mediatheque/issues/69)): every probe recorded as a run, `queued_at` on renditions, `IngestRejected`, indexes for periods ([#71](https://github.com/folkloreinc/laravel-mediatheque/issues/71)); ended runs pruned by `mediatheque:prune`; the `Monitoring` read model and `mediatheque:status` ([#75](https://github.com/folkloreinc/laravel-mediatheque/issues/75)); `retry()`, `cancel()`, `refresh()` and `probeAgain()` ([#73](https://github.com/folkloreinc/laravel-mediatheque/issues/73))
- [x] JSON resources of runs and renditions, `PipelineRunResource` and `PipelineJobResource` ([#77](https://github.com/folkloreinc/laravel-mediatheque/issues/77))
- [x] The monitoring dashboard: a precompiled React SPA on `@panneau/ui`, `Route::mediathequeDashboard()`, its two gates, its CI job ([#81](https://github.com/folkloreinc/laravel-mediatheque/issues/81))

Released with [#85](https://github.com/folkloreinc/laravel-mediatheque/issues/85).

## Content and usages (2.0.0-alpha.5 to alpha.8)

- [x] `replaceOriginal()` ([decisions/0019](decisions/0019-replace-original.md), [#87](https://github.com/folkloreinc/laravel-mediatheque/issues/87)), in 2.0.0-alpha.5
- [x] `HasMedias` with `attachMedia`, `syncMedias`, `media()`; usage graph; media in use protected from deletion ([decisions/0020](decisions/0020-attachments-by-handle-and-usages.md), [#92](https://github.com/folkloreinc/laravel-mediatheque/issues/92)), in 2.0.0-alpha.6
- [x] Migrations that run on MySQL, and a CI job on MySQL 8.4 ([#98](https://github.com/folkloreinc/laravel-mediatheque/issues/98)), in 2.0.0-alpha.7
- [x] The handle of JSON references in the usage graph is `json`, for every JSON column of a model, as `laravel-json-data` and `laravel-folklore` 2 sync it ([decisions/0021](decisions/0021-json-references-handle.md), [#104](https://github.com/folkloreinc/laravel-mediatheque/issues/104)), in 2.0.0-alpha.8
- [x] The test suite on PostgreSQL, with `jsonb` ([#103](https://github.com/folkloreinc/laravel-mediatheque/issues/103)), in 2.0.0-alpha.8
- [x] Path placeholders that read a model accessor, for a tenant's public id ([#64](https://github.com/folkloreinc/laravel-mediatheque/issues/64)), in 2.0.0-alpha.8

## Protected playback (2.0.0-alpha.8 to alpha.9, continues)

What the pilot needs for its reserved videos. URL import ([#91](https://github.com/folkloreinc/laravel-mediatheque/issues/91)) comes in the alpha that follows, if the pilot needs it.

- [x] Visibility changes, which update the files and dispatch `MediaVisibilityChanged` ([#119](https://github.com/folkloreinc/laravel-mediatheque/issues/119)), in 2.0.0-alpha.9
- [x] CDN prefixes for public files: the disk's `url` ([#128](https://github.com/folkloreinc/laravel-mediatheque/issues/128))
- [ ] A `UrlSigner` for CDNs that sign their own URLs ([#129](https://github.com/folkloreinc/laravel-mediatheque/issues/129)), when a project needs one
- [x] Temporary URLs that download the file, with its name ([#102](https://github.com/folkloreinc/laravel-mediatheque/issues/102)), in 2.0.0-alpha.8
- [x] The progress of runs: null until measured, weighted by the input's duration, `Media::processing()` for Panneau 5's media field, and `RenditionProgressed` ([decisions/0023](decisions/0023-progress-of-runs.md), [#117](https://github.com/folkloreinc/laravel-mediatheque/issues/117)), in 2.0.0-alpha.9
- [x] `MediaResource` and `FileResource`, with the meaning of Panneau 5's `MediaInfo` in snake_case, and `Media::failure()`; `laravel-folklore` keeps its legacy shape ([#133](https://github.com/folkloreinc/laravel-mediatheque/issues/133))
- [x] The `transform`, focal point and `orientation` of an image, settled with `laravel-image` 2 and Panneau 5 ([laravel-image#88](https://github.com/folkloreinc/laravel-image/issues/88)), then stored on the media ([#107](https://github.com/folkloreinc/laravel-mediatheque/issues/107)), in 2.0.0-alpha.8
- [x] GPS removal at ingest, as the `Ingestor` contract says ([#120](https://github.com/folkloreinc/laravel-mediatheque/issues/120)), in 2.0.0-alpha.9
- [ ] Images at ingest: HEIC, ThumbHash placeholder (the orientation is read since 2.0.0-alpha.8)
- [x] `mediatheque:prune` of soft-deleted media and ended runs, and the stored files of force-deleted media deleted in the queue ([#121](https://github.com/folkloreinc/laravel-mediatheque/issues/121)), in 2.0.0-alpha.9

## Parity with 1.x

- [ ] Documents: page thumbnails, page count
- [ ] Fonts: web fonts, family names
- [ ] Animated GIF and WebP as video
- [ ] URL import behind an SSRF guard (`Source::url()`, [#91](https://github.com/folkloreinc/laravel-mediatheque/issues/91)), which `laravel-folklore` 2's media references then use
- [ ] `mediaconvert` transcoder, asynchronous, polled by `mediatheque:refresh`
- [ ] `mediatheque:doctor`, `mediatheque:run-pipeline`
- [ ] Custom types, renditions and operations through the config and `extend*()`

## Beta: upgrade path and Eclair

- [ ] Migration from 1.x and `mediatheque:upgrade` (`uid` backfill, metadata from the EAV table, serialized definitions, grouped handles, foreign keys after orphan cleanup), with `--check`
- [ ] Compatibility layer: 1.x model methods, handles, `medias://` references, 1.x config shapes, `legacy` JSON resource, tested on 1.x fixtures
- [ ] Rector rules
- [ ] Upgrade validated on a copy of an existing project's database. Sites on 1.x upgrade through `laravel-folklore` 2: `laravel-folklore` 1.1 stays on this package's 1.x
- [ ] `eclair` transcoder in `laravel-eclair`, with its webhook and probe. It works with Eclair's current protocol; these additions to Eclair's `/v1` remove workarounds ([decisions/0011](decisions/0011-renditions-on-remote-transcoders.md), [0012](decisions/0012-pipeline-runs-recorder-and-probe.md)):
  - `width`, `height`, `duration` and `bitrate` on output files (until then, each rendition is probed again);
  - an explicit `index` on files of a group (until then, inferred from file names);
  - a reason on skipped tasks (until then, a generic reason);
  - a visibility applied to outputs (until then, an ACL request per file);
  - `metadata` on probe requests (optional: the driver matches the probe job by its id);
  - a task-level input (optional, later: partial reruns would stop producing their inputs again).
- [ ] HLS rendition, with private playback (signed segments)
- [ ] Derived media: clips with start, end and crop
- [ ] `captions` type; transcription through transcoders that support it
- [ ] The JSON resources of runs and renditions reviewed with Panneau 5
- [ ] The monitoring dashboard validated on the pilot project, on the current `@panneau/ui` alpha ([#106](https://github.com/folkloreinc/laravel-mediatheque/issues/106))

## Release candidate

- [ ] Public API frozen; contracts documented
- [ ] README rewritten; upgrade guide complete
- [ ] The pilot project runs the RC in production

Needed before the pilot launches: `laravel-mediatheque`, `laravel-image` and Panneau must be at least in RC.

## 2.0.0

- [ ] Every item above done, CI green on the whole matrix
- [ ] `laravel-eclair` usable in production with 2.0

2.0.0 depends on `laravel-eclair`, so it may ship after the pilot's launch, which runs the RC until then.

## After 2.0.0

- Cloudflare Stream transcoder, with remote renditions, if a project needs it
- Storyboards, vertical reframing (9:16), scene detection, highlights, through Eclair
- Upgrade of the active 1.x projects, through `laravel-folklore` 2 first
- Monitoring, if a project needs it: a Pulse recorder, an aggregates table ([decisions/0017](decisions/0017-monitoring-dashboard.md)). Broadcasting stays the application's, from `RenditionProgressed` and the other events ([decisions/0023](decisions/0023-progress-of-runs.md))
- 3.0, later: remove the compatibility layer and the unused 1.x tables

## Open questions

- How private HLS is signed: playlist rewriting or CDN tokens (decided in the beta).
