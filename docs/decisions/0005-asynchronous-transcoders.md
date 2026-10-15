# 0005: An asynchronous `Transcoder` contract and declarative renditions

- Status: accepted
- Date: 2026-10-15

## Context

1.x runs every job in the application's queue and stores its pipeline definitions as serialized PHP objects. Its MediaConvert job polls with `sleep(10)` and holds a worker for the whole transcode.

2.0 must support several ways of processing media:

- local ffmpeg and Imagick, in the application's queue;
- Eclair, a shared server that takes a whole task graph as one job, writes its outputs to the application's storage and calls a signed webhook;
- AWS MediaConvert, a job API with completion notifications;
- later, hosted video services such as Cloudflare Stream, which keep the media and call a webhook.

Most of them are asynchronous and report through callbacks. Only the local driver runs inside the application.

## Decision

- **A `Transcoder` receives a whole pipeline run**, not one rendition at a time: `submit(PipelineRun): ?string` returns the driver's job id. `cancel()` and optional capabilities (progress, cancel, probe, remote renditions) complete it.
- **Drivers report through a `Recorder`**: `started`, `progress`, `completed` (files and metadata outputs), `skipped`, `failed`. Every call is idempotent, because webhooks are delivered more than once and queue jobs are retried.
- **Renditions are declarative**: an operation, options, `from`, `when`, `keep` and `queue`, stored as a JSON snapshot on the run. Operation names and options follow Eclair's vocabulary (`video.transcode`, `video.thumbnails`, `audio.waveform`…), so a pipeline means the same thing on every driver.
- **One transcoder per run.** A pipeline that needs two transcoders is split into two pipelines.
- Drivers for 2.0.0:
  - **`ffmpeg`**, in this package, built on `symfony/process` (no `php-ffmpeg`);
  - **`eclair`**, in `laravel-eclair`;
  - **`mediaconvert`**, in this package, rewritten to be asynchronous (EventBridge notifications or a scheduled poller).
- Cloudflare Stream comes in 2.x if a project needs it. The contract already allows remote renditions ([0004](0004-renditions.md)).

## Consequences

- The local driver is the special case: it turns the run into queued jobs and dispatches dependent renditions when their input completes.
- `mediatheque:doctor` checks that each pipeline's transcoder supports its operations, so a misconfiguration shows at deploy time, not on the first upload.
- The 2.0.0 release depends on `laravel-eclair` being usable. The pilot project can run release candidates in production before that.
- Custom 1.x `PipelineJob` subclasses become custom operations of the ffmpeg driver ([upgrade.md](../upgrade.md)).
