# 0023: The progress of runs, on the media, and an event

- Status: accepted
- Date: 2026-10-28
- Amends: [0012](0012-pipeline-runs-recorder-and-probe.md), [0017](0017-monitoring-dashboard.md)

## Context

[0012](0012-pipeline-runs-recorder-and-probe.md) records a progress from 0 to 1 for each rendition (`pipelines_jobs.progress`), which never goes down, and a run's progress is the mean of its renditions, an ended rendition counting as 1. Three things are missing ([#117](https://github.com/folkloreinc/laravel-mediatheque/issues/117)):

- **Unknown reads as 0 %.** Many renditions are never measured: a transcoder that reports no progress (`ProgressReporting::None`, Eclair's case, since it never pushes progress), ffmpeg when the probe found no duration, operations that report nothing (a poster). Their progress stays at the column's default, 0, and the JSON resources, `mediatheque:status` and the dashboard show "0 %" for a transcode that is half done.
- **Every rendition weighs the same.** A poster or five thumbnails take a second; a transcode takes minutes. With `h264`, `poster` and `thumbnails`, a run reads 67 % as soon as its two short renditions end, while the transcode has barely started.
- **The media doesn't expose its progress.** Panneau 5's media field and the pilot project want to show "transcoding: 42 %" next to a video ([panneau-js#84](https://github.com/folkloreinc/panneau-js/issues/84), question 3). Today they would read the media's runs and renditions and compute it themselves.

An application may also want progress live, over Reverb or another broadcaster. The package knows nothing about tenants or who may see a media, so it can't choose a channel.

## Decision

### A nullable progress

- `PipelineJob::progress()` and `PipelineRun::progress()` return `?float`. **Null means that nothing measured it**; 0 means that a driver reported 0.
- **A nullable column**, `null` by default, on `pipelines_jobs` and `pipelines`, rather than a flag next to a 0. Null is what the value is: one column, no second column to keep in sync with the first, and the recorder's rule (progress never goes down) reads `max(previous ?? 0, reported)`. A migration makes both columns nullable and turns the 0 of renditions that didn't complete, and of runs that haven't ended, into null: no driver reports exactly 0, so those zeros were never measured.
- A completed rendition is 1. A rendition that failed, was skipped or canceled keeps what was measured, null included: its status says how it ended.
- The JSON resources expose `progress: null`, and the dashboard shows an indeterminate bar for a running run or rendition that nothing measured, and a dash otherwise.

### A weighted mean for a run

- A rendition that transcodes the whole input (`video.transcode`, `video.hls`, `video.reframe`, `audio.transcode`) **weighs the input's duration** in seconds, from the media's `duration` metadata. Any other rendition (poster, thumbnails, waveform, probe) **weighs one second**. Without a duration, every rendition weighs the same, as before.
- **An ended rendition is done**, whatever its status.
- **A rendition that nothing measured counts as not started**, rather than being left out of the mean. Left out, a run on Eclair would read 100 % as soon as its poster ended, since the transcode is never measured; and the run's progress could go down when a rendition reports its first measure. Counted as not started, the run's denominator is fixed, so its progress never goes down, and a run on a transcoder without progress moves in steps as its renditions end.
- **A run is null until one of its renditions is measured or ended.**
- `PipelineRun::progress()` computes it from the renditions (`Pipelines\Progress`), for the Eloquent models and the fake alike. The recorder also stores it in `pipelines.progress` with each call, so queries can read it; nothing in the package reads that column.

### `Media::processing()`

- On the `Media` contract, the Eloquent model and the fake: a readonly `Pipelines\Processing` with the media's `status`, the uid of the run in progress (`run`), its `pipeline`, its `progress` (`?float`) and the `rendition` that is running (the first running one in the run's order, or null while none started). **Null when no run is in progress.**
- With several runs in progress (a rerun of a rendition while the pipeline runs), it is the latest one.
- One query on the Eloquent model, the run with its renditions. An application that lists many media eager-loads the runs itself, or reads `processing()` only for media that are `pending` or `processing`.

### `RenditionProgressed`

- An event with the job (contracts, as every event), the rendition's progress and the run's.
- The recorder dispatches it **each time a rendition's progress passes a multiple of `events.progress_step`** (0.05 by default): once per call, however many steps it passed. Passing a multiple, rather than moving a step from the last event, needs no state beyond the stored progress, and holds when calls arrive twice or out of order.
- It follows the recorder's rules: **no event for a call that changes nothing** (a duplicated, late or lower report, a report on an ended rendition). **No event for a status change** either: completing a rendition sets its progress to 1, and `RenditionCompleted` says so.
- The fake's recorder dispatches it the same way, so a test asserts it with `Event::fake()`; `Mediatheque::fake()->progressRendition()` reports a progress as a driver would.

### No realtime in the package

- **The package broadcasts nothing.** Clients poll `processing()` or the resources, as the dashboard does. [0017](0017-monitoring-dashboard.md) left broadcasting the package's events over Reverb for later: it stays the application's.
- An application that wants progress live broadcasts `RenditionProgressed` from its own listener, on channels it authorizes (`docs/architecture.md`, Broadcasting progress). Tenant isolation is its job ([AGENTS.md](../../AGENTS.md), rule 3).

## Consequences

- **Breaking between alphas**: code that reads `progress()` as a float handles null, and an application that installed an earlier alpha publishes and runs the new migration (`mediatheque:install`).
- The weights are fixed in the package. A transcode of a short clip still weighs more than its poster; a custom operation that reads the whole input weighs a second until it is added to `Progress::TRANSCODES`.
- A run on a transcoder without progress reads null until its first rendition ends, then moves in steps. Eclair pushing progress later would make it continuous without changing this decision.
- Each recorder call loads the run's media to weigh its renditions, and saves the run when its progress changed: one more query and at most one more write per call.
- `RenditionProgressed` fires at most 1 / `progress_step` times per rendition: 20 by default. The `ffmpeg` driver already reports at most every 5 %.
