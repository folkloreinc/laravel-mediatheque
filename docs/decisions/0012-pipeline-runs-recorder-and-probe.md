# 0012: Pipeline runs, the recorder and an asynchronous probe

- Status: accepted, amended by [0014](0014-run-outcomes-and-model-contracts.md) and [0017](0017-monitoring-dashboard.md)
- Date: 2026-10-16
- Amends: [0005](0005-asynchronous-transcoders.md)

## Context

Eclair's review of the `Transcoder` contract ([comment on #24](https://github.com/folkloreinc/laravel-mediatheque/pull/24#issuecomment-6204765550)) found that [0005](0005-asynchronous-transcoders.md) leaves open what a remote driver needs to know when it submits a run and when it reports back:

- **Partial reruns.** A rerun produces only the missing, failed or changed renditions. If `webm` reads `from: h264` and `h264` is already done, an Eclair job has nothing to read it from: a job has one input, and `from` names a task of the same job.
- **Retries.** Retrying failed renditions could create a new run or retry the same driver job. With the same job, the recorder can't tell a late webhook of the first attempt from one of the second.
- **`queue`.** Eclair routes tasks by capability, not by queue.
- **The recorder.** Eclair sends the whole job on every webhook, retries deliveries for 24 hours and can deliver them more than once, so an older snapshot can arrive after a newer one. Eclair cancels tasks on its own. Its error codes are stable. After 24 hours it stops delivering, and it never pushes progress. MediaConvert needs a poller for the same reasons.
- **The probe.** Eclair's probe answers with the metadata, or with a job when it takes longer than its timeout. The `Prober` contract had no pending outcome.
- **Outputs.** A remote transcoder names its files itself, writes objects with the bucket's default ACL, and must know before it starts which storage it writes to.

## Decision

### Runs

- **A run's renditions are closed under `from`.** When a rerun includes a rendition, it also includes the rendition it reads from, which is produced again even if it exists. Every rendition in a run is produced by that run. Eclair has offered a task-level input (a storage path) that would avoid producing the input again; it can be adopted later as a driver capability without changing this rule.
- **Each retry creates a new run**, with its own driver job and `external_id`. The previous run keeps its status and history. A webhook for the previous run's job finds that run, which has ended, and changes nothing. Drivers never retry a job in place (Eclair's `POST /v1/jobs/{id}/retry` is not used).
- **`queue`** applies to the `ffmpeg` driver only. Remote drivers ignore it, and `mediatheque:doctor` warns when a pipeline on a remote driver sets it.
- **Output source:** a pipeline sets the source its renditions are written to (`source`), the original's source by default. The run stores it, so the driver knows it when it submits.
- **Output paths:** the path formatter builds a **prefix per run** (`run_path_format`, with the media's placeholders and `{run}`). Remote drivers write their files under that prefix with names they choose; the file records the path the driver reports. Only the `ffmpeg` driver also builds each file's path with `file_path_format`. Consumers read `$file->path` and never rebuild it.
- **Visibility:** the run passes the media's visibility to the driver, which writes its outputs with it. A visibility change during a run is applied to that run's files when it ends.

### Recorder

- **Statuses only move forward:** `queued` → `running` → `completed`, `failed`, `skipped` or `canceled`. A call that would move a rendition back, or change a final status, is ignored. Progress never goes down. This is how a driver reconciles a whole snapshot on every delivery, in any order.
- **`canceled(PipelineJob $job)`** joins `started`, `progress`, `completed`, `skipped` and `failed`, for renditions the driver canceled on its own (fail-fast, an operator).
- **Errors have a code from an enum**, `TranscoderErrorCode`, with the values of Eclair's task error codes: `input_unreadable`, `unsupported_input`, `timeout`, `limit_exceeded`, `operation_failed`, `storage_failed`, `provider_failed`, `dependency_failed`. Every driver maps its errors to them. An unknown code is read as `operation_failed`.
- **Refresh:** a driver with the `refresh` capability implements `refresh(PipelineRun $run)`, which fetches the run's state from the driver and reports it through the recorder. A scheduled command, `mediatheque:refresh`, calls it for runs that have been running longer than a threshold (lost webhooks), and for every running run of drivers that report progress only by polling. The MediaConvert poller is this command.

### Probe

- **The `Prober` contract is asynchronous:** `probe()` returns the metadata, or a pending outcome with the driver's external id.
- A pending probe is recorded as a run of the `probe` pipeline (one rendition, operation `probe`, no files), with the prober's driver and external id. The driver completes it through the recorder, like any run; its metadata outputs go to the media ([0011](0011-renditions-on-remote-transcoders.md)). Webhook matching, `refresh`, retries and errors work as for other runs.
- The media stays `pending` until the probe completes. `MediaProbed` is dispatched then, and the type's pipeline starts on `MediaProbed`, never before. A failed probe fails the media.

## Consequences

- A partial rerun of a rendition with `from` costs one more transcode of its input.
- The `ffmpeg` driver's queue retries stay within one run; its jobs keep `attempts`. A retry asked by the application or by `mediatheque:run-pipeline` is a new run.
- `mediatheque:refresh` is scheduled by the application, like `mediatheque:prune`. `mediatheque:doctor` reports when it isn't scheduled and a pipeline uses a driver that needs it.
- Files of each run live under their own prefix, so a rerun never overwrites a file that a previous run produced and that is still in use. Replaced files are deleted in a queued job.
- Until Eclair applies a visibility to its outputs, the `eclair` driver sets each file's ACL after recording it: one request per file, more for HLS.
- Until Eclair accepts `metadata` on probe requests, the `eclair` driver matches a probe's webhook to its run by the job id, which the run already stores.
