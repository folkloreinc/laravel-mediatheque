# Roadmap of 2.0

2.0 is built on the `v2.x` branch in slices, each released as a tagged pre-release (`v2.0.0-alpha.N`, then beta and RC). The pilot project, Niche, requires `^2.0@alpha` and pulls each slice from its own needs. 1.x stays maintained on `v1.1` for fixes and security only.

Tracking issues: [#1](https://github.com/folkloreinc/laravel-mediatheque/issues/1) (overall plan) and [#23](https://github.com/folkloreinc/laravel-mediatheque/issues/23) (kickoff). Each item becomes a sub-issue when its work starts.

## Rules

- **Contracts first.** Each public contract is written with its tests before its implementation, and reviewed against its consumers (`laravel-image`, `laravel-eclair`, Panneau 5) before the alpha that ships it.
- **Each alpha is usable end to end** for what it contains, with docs. Nothing half-done is tagged.
- Until the release candidate, an alpha can break the previous one. Breaking changes between alphas are listed in the CHANGELOG.
- Releases are tagged from a green CI on `v2.x`.

## Phase 0: kickoff

- [x] `v2.x` branch, created from `v1.1`
- [x] `docs/`: current state, architecture, decisions, this roadmap, upgrade guide
- [x] Tooling: PSR-4, PHP 8.3+, Laravel 12+, CI matrix (PHP 8.3 to 8.5 × Laravel 12 and 13), Pint, Larastan at the maximum level without a baseline for new code ([#28](https://github.com/folkloreinc/laravel-mediatheque/issues/28))
- [ ] Remove the 1.x code from `v2.x` and start the new layout; keep 1.x fixtures for the compatibility tests
- [ ] Release process: changelog, `v2.0.0-alpha.N` tags from green CI
- [ ] Contracts and their tests: `Media`, `File`, `uid` and references, `Ingest`, `Prober` (asynchronous), `Transcoder` and `Recorder`, `UrlGenerator`, events, `Mediatheque::fake()`
- [x] Review the `Transcoder` contract with Eclair ([urbania-media/eclair#13](https://github.com/urbania-media/eclair/issues/13), [review](https://github.com/folkloreinc/laravel-mediatheque/pull/24#issuecomment-6204765550)); answered in [decisions/0011](decisions/0011-renditions-on-remote-transcoders.md) and [0012](decisions/0012-pipeline-runs-recorder-and-probe.md)
- [ ] Review the image resolver with `laravel-image` 2 ([laravel-image#59](https://github.com/folkloreinc/laravel-image/issues/59))

## Alpha 1: upload and play a video

What the pilot's media library needs first, end to end.

- [ ] Schema: `medias` with `uid`, `status`, `visibility`, `metadata`, soft deletes; `files` with `media_id`, `handle`, `index`, `role`, 64-bit `size`; pipeline runs and jobs with statuses
- [ ] Migrations owned by the application (`mediatheque:install` publishes them) ([#22](https://github.com/folkloreinc/laravel-mediatheque/issues/22))
- [ ] `Mediatheque::ingest()` from an uploaded file and from a file on a disk, with content-based types and validation
- [ ] Presigned multipart uploads to an S3-compatible disk (opt-in routes, policy)
- [ ] Path formatter that receives the media, with a prefix per run
- [ ] Queued probe (ffprobe, Imagick) through the asynchronous `Prober` contract
- [ ] `ffmpeg` transcoder: web video rendition (`h264`), poster and thumbnails
- [ ] `url()` and `temporaryUrl()`, public and private media
- [ ] Events (`MediaCreated`, `MediaProbed`, `Rendition*`, `Pipeline*`, `MediaReady`)
- [ ] `Mediatheque::fake()` with assertions
- [ ] `laravel-image` 2 resolver, so images ingested here get presets and signed URLs

Images: the alpha stores and probes them; their renditions come from `laravel-image` 2's first alpha.

## Alpha 2: content and protected playback

- [ ] Visibility changes, CDN prefixes, `UrlSigner` contract
- [ ] `HasMedias` with `attachMedia`, `syncMedias`, `media()`; usage graph; media in use protected from deletion
- [ ] `media://` references: parser, batch resolution
- [ ] Soft deletes, `mediatheque:prune`, deferred file cleanup
- [ ] `replaceOriginal()`
- [ ] `MediaResource` and `FileResource`, in sync with Panneau 5's protocol
- [ ] Contract for Panneau 5's media adapter ([laravel-panneau#35](https://github.com/folkloreinc/laravel-panneau/issues/35))
- [ ] Images at ingest: HEIC, orientation, GPS removal, blurhash
- [ ] SVG sanitizing

## Alpha 3: parity with 1.x

- [ ] Audio: `mp3` rendition and waveform
- [ ] Documents: page thumbnails, page count
- [ ] Fonts: web fonts, family names
- [ ] Animated GIF and WebP as video
- [ ] URL import behind an SSRF guard
- [ ] TUS adapter
- [ ] `mediaconvert` transcoder, asynchronous, polled by `mediatheque:refresh`
- [ ] `mediatheque:doctor`, `mediatheque:run-pipeline`, `mediatheque:refresh`
- [ ] Custom types, renditions and operations through the config and `extend*()`

## Beta: upgrade path and Eclair

- [ ] Migration from 1.x and `mediatheque:upgrade` (`uid` backfill, metadata from the EAV table, serialized definitions, grouped handles, foreign keys after orphan cleanup), with `--check`
- [ ] Compatibility layer: 1.x model methods, handles, `medias://` references, 1.x config shapes, `legacy` JSON resource
- [ ] Rector rules
- [ ] Upgrade validated on a copy of an existing project's database, and against `laravel-folklore` 1.1's test suite
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

## Release candidate

- [ ] Public API frozen; contracts documented
- [ ] README rewritten; upgrade guide complete
- [ ] The pilot project runs the RC in production

Needed before the pilot launches: `laravel-mediatheque`, `laravel-image` and Panneau must be at least in RC.

## 2.0.0

- [ ] Every item above done, CI green on the whole matrix
- [ ] `laravel-eclair` usable in production with 2.0
- [ ] `v2.x` becomes the default branch

2.0.0 depends on `laravel-eclair`, so it may ship after the pilot's launch, which runs the RC until then.

## After 2.0.0

- Cloudflare Stream transcoder, with remote renditions, if a project needs it
- Storyboards, vertical reframing (9:16), scene detection, highlights, through Eclair
- Upgrade of the active 1.x projects, through `laravel-folklore` first
- 3.0, later: remove the compatibility layer and the unused 1.x tables

## Open questions

- Which TUS server library to use (decided when alpha 3 starts).
- How private HLS is signed: playlist rewriting or CDN tokens (decided in the beta).
- Whether a `laravel-folklore` 1.x minor accepts `^1.1|^2.0` of this package, or projects wait for `laravel-folklore` 2.
