# 0028: A span of a video, with `start` and `duration` of `video.transcode`

- Status: proposed
- Date: 2026-11-09
- Amends: [0023](0023-progress-of-runs.md), [0026](0026-h264-for-the-web-and-passthrough.md)

## Context

An application wants a short public preview of a video it doesn't give to everyone: its first 15, 30 or 60 seconds, as a rendition of its own, so that the whole video is never sent to someone who may not watch it ([#161](https://github.com/folkloreinc/laravel-mediatheque/issues/161)). No operation cuts a video. "Derived media: clips with start, end and crop" is on the roadmap, but a clip is a media of its own, with its own pipeline, where a preview is one more file of the same media.

Eclair adds two options to its `video.transcode` for the same need ([urbania-media/eclair#95](https://github.com/urbania-media/eclair/issues/95)). The `ffmpeg` driver keeps the same options as Eclair's, so that a pipeline means the same thing on both ([0005](0005-asynchronous-transcoders.md)).

## Decision

- **`video.transcode` takes `start` and `duration`**, in seconds, integers or floats: the output keeps the input from `start` (0 by default) for `duration` (until the end by default), cut at the end of the input. The names are Eclair's; `laravel-eclair` passes them through as they are.
- On the `ffmpeg` driver, `start` is an input `-ss`, so that ffmpeg seeks rather than decoding up to it, and `duration` an output `-t`. The driver refuses a `start` that isn't a number of seconds 0 or more, or a `duration` that isn't more than 0 (`operation_failed`), and a `start` at or after the end of the input (`unsupported_input`). A rendition that should only run on long enough videos says so with `when.min_duration`.
- **With `passthrough: true`**, a video that already fits is copied for that span only ([0026](0026-h264-for-the-web-and-passthrough.md)). A copy can only start on a keyframe: it starts at the keyframe at or before `start`. A cut from 0, the case of a preview, starts on a keyframe.
- **The progress of a run weighs such a rendition by its span**, amending [0023](0023-progress-of-runs.md): a `video.transcode` with `start` or `duration` weighs the seconds it encodes, the span cut at the end of the input, rather than the input's duration. The `ffmpeg` driver also measures the rendition's own progress on the span. `Pipelines\Span` computes it, for `Progress`, the driver and the fake alike.
- **The output file's `duration` is the span's.** The `ffmpeg` driver probes what ffmpeg wrote, as for any rendition; `FakeTranscoder` gives its placeholder file the span as its duration, so that an application can test it.
- Only `video.transcode` reads them. `audio.transcode` and `video.hls` keep the whole input until an application needs a span of them.

## Consequences

- A preview is a rendition like any other: a separate pipeline run on demand (`Mediatheque::runPipeline($media, 'video_preview', ['preview_15'])`), one rendition per length, each served and protected as its media's files are.
- A pipeline with `start` or `duration` needs a version of Eclair whose `video.transcode` knows them: an older one refuses the options.
- Clips with an end, a crop or as media of their own stay on the roadmap. A copied span from a `start` that isn't on a keyframe begins a little early; an application that needs an exact start leaves `passthrough` off.
