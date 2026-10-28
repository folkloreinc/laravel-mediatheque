# 0014: How runs end, the media's status, and model contracts

- Status: accepted
- Date: 2026-10-16
- Amends: [0012](0012-pipeline-runs-recorder-and-probe.md)

## Context

The contracts of phase 0 (#36) settled points that the architecture left open, and listed them for review before alpha 1 implements them in Eloquent:

- when a run ends, and with which status;
- which rendition decides whether a media is `ready`;
- what tells an application that a media failed;
- how model contracts expose their data, since PHP 8.3 interfaces can't declare properties.

One case wasn't covered: a run that produces the main rendition again, on a media that is already `ready`, and fails.

## Decision

### End of a run

- A run ends when every rendition in it has a final status: **`failed`** if one failed, else **`canceled`** if one was canceled, else **`completed`**. A skipped rendition is a normal outcome.
- A canceled rendition cancels the renditions that read from it, as failures and skips propagate to theirs.
- `PipelineCompleted`, `PipelineFailed` or `PipelineCanceled` is dispatched once, when the run ends.

### The main rendition and the media's status

- A pipeline's **main rendition** is the first one, in the order of its definition, with the `main` role (the default role) that keeps its output (`keep` is not `false`). `video` has `h264`.
- A media goes `pending` → `processing` when its probe completes → `ready` or `failed`.
- After a run that includes the main rendition, the media is **`ready`** if the main rendition completed, and **`failed`** if it failed, was skipped or was canceled (a skipped main rendition makes it `ready` since 2026-10-28, below). A run that doesn't include it (thumbnails only) leaves the media's status as it is.
- **A `ready` media stays `ready`** when a later run fails to produce its main rendition again, if the media still has a file for it: the previous file is still served, since a run's files only replace the previous ones when they are recorded. The failure is on the run and its events. A `failed` media becomes `ready` when a later run completes its main rendition.
- A type without a pipeline (images), or a pipeline without a main rendition, makes the media `ready` once it is probed.
- A skipped main rendition makes the media `ready`, played from its original (amended on 2026-10-28, [#132](https://github.com/folkloreinc/laravel-mediatheque/issues/132)): skipping is a normal outcome, and a condition (`when`) on a main rendition says the inputs it doesn't match need none, as the `image` pipeline's `web` rendition does for every image but HEIC, HEIF and AVIF. So a media whose main rendition's condition doesn't match its probe is `ready` once it is probed, and a pipeline none of whose kept renditions' conditions match the probe doesn't run at all: a JPEG runs nothing. A main rendition that failed or was canceled still fails the media.

### Events

- **`MediaReady`** is dispatched when the media becomes `ready`, and **`MediaFailed`** when it becomes `failed`: its probe failed, or its main rendition didn't complete. `MediaFailed` carries the error when there is one (the probe's or the main rendition's). Neither is dispatched again while the status doesn't change.

### Model contracts are methods

- The `Media`, `File`, `PipelineRun` and `PipelineJob` contracts expose methods (`uid()`, `status()`, `files()`), not properties.
- The package's Eloquent models implement them, and name their relations so they don't clash with the contracts:

  | Model | Contract method | Eloquent relation |
  |---|---|---|
  | `Media` | `files(?string $handle)`, a collection | `mediaFiles()` |
  | `Media` | — | `pipelineRuns()` |
  | `File` | `visibility()`, its media's | `media()` |
  | `PipelineRun` | `media()`, `jobs()` | `pipelinable()`, `pipelineJobs()` |
  | `PipelineJob` | `run()` | `pipelineRun()` |

- `File` has no `media()` method in its contract: it exposes `visibility()`, what the URL generator needs, so the Eloquent `media()` relation stays free.
- Package code reads models through the contracts. It uses the relations only inside the Eloquent implementations (the recorder, the resolver), where it eager-loads them.

## Consequences

- 1.x code that reads `$media->files` as a relation gets a collection from `files()` instead, and the compatibility layer (beta) keeps `$media->files` working through an accessor.
- A rerun that fails doesn't take a playing video offline. An application that wants to know reads the run's status or listens to `PipelineFailed`.
- The fake's recorder and the Eloquent recorder pass the same contract tests, including the rule for a `ready` media.
