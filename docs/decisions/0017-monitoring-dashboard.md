# 0017: A monitoring dashboard

- Status: proposed
- Date: 2026-10-21

## Context

2.0 records what it does in its own tables. `pipelines` holds runs, `pipelines_jobs` holds renditions and `uploads` holds presigned uploads. Each row has a status enum, progress, attempts, an error code and timestamps ([0012](0012-pipeline-runs-recorder-and-probe.md), [0014](0014-run-outcomes-and-model-contracts.md), [0015](0015-new-install-schema-attributes-and-uploads.md)). Nothing shows these rows yet. To find out why a video doesn't play, or which runs are stuck, a developer reads the tables or the logs. Horizon, where an application runs it, shows `RunRendition` queue jobs, but not runs, renditions or media.

Developers want a view of what the package is doing, in the spirit of Horizon, Telescope or Pulse: runs, renditions, probes, uploads, failures and retries, with the actions that go with them. **It is an observation tool for developers, not an admin for editors.** Panneau 5 stays the admin ([0009](0009-package-boundaries.md)): it shows a media's status to the people who edit content, and the dashboard shows the pipeline to the people who run the application.

### What the dashboard shows

| View | Content | Source |
|---|---|---|
| Overview | Media by status; active runs; runs ended in the last hour or day, by status; stuck runs (running longer than `mediatheque:refresh`'s threshold); the oldest queued rendition | Tables |
| Runs | Filtered by status, pipeline, transcoder, media type, error code, period, media uid; with the media, transcoder, `external_id`, progress, attempts, duration and error | Tables |
| A run | Its renditions as a graph (`from`): status, progress, attempts, error or skip reason, duration, the files they produced (handle, size, dimensions); the runs before and after it on the same media | Tables |
| A media | Its status, metadata, files and every run, its probe included | Tables |
| Failures | Failed runs and renditions grouped by error code, pipeline and transcoder over a period, with the last message of each group | Tables |
| Uploads | Open, completed, aborted and expired uploads, with size, creator, age and the media they created | Tables, with gaps (below) |
| Durations and throughput | Duration of each rendition by operation and transcoder (median, p95); runs per hour; bytes ingested and produced | Tables over a bounded period; aggregates beyond |
| Probes | Probe durations and failures | Gap (below) |
| Queue wait | Time between dispatch and start, by queue and rendition | Gap (below) |

Actions:

- **Retry:** a new run of a run's failed and canceled renditions ([0012](0012-pipeline-runs-recorder-and-probe.md)).
- **Cancel:** for a driver with the `cancel` capability.
- **Refresh:** for a driver with the `refresh` capability.
- **Probe again:** a media whose probe failed.
- **Run a pipeline:** on a media.

### What the tables don't have

- **Synchronous probes leave no record.** Only a pending probe becomes a run of the `probe` pipeline ([0012](0012-pipeline-runs-recorder-and-probe.md)). When the local prober fails, the media becomes `failed` and the error only exists in the `MediaFailed` event: `medias` has no error column. Probe durations are not recorded either.
- **Renditions have no dispatch time.** `pipelines_jobs.created_at` is when the run was created. With the `ffmpeg` driver, a rendition that reads from another one is dispatched later, when its input completes, so the wait in the queue can't be computed.
- **Uploads don't say how they ended.** `aborted` covers an abort by the creator, a file rejected at ingest and an expired upload, with no reason and no end time.
- **Rejected ingests leave nothing.** `ingest()` throws `FileRejected` and writes no row; there is no event to count them.
- **History goes away with the media, and only with it.** Force-deleting a media deletes its runs (cascade); nothing prunes the runs of the media that stay, so the tables grow without limit.
- **Indexes.** `pipelines` and `pipelines_jobs` are indexed on `status` only. Queries over a period need `ended_at` and `created_at`.
- **Aggregates over long periods** (throughput per hour over a month, p95 by operation) can be computed from the tables at the pilot's volumes. Ten times more, or a history longer than runs are kept, needs aggregates collected as events happen, as Pulse does.

### Options

State checked on 2026-10-21 on Packagist and the projects' repositories:

- Pulse 1.8.1 supports Laravel 10 to 13 and requires Livewire 3.6 or 4; its storage requires MySQL, MariaDB or PostgreSQL.
- Livewire is at 4.4.8.
- Filament 5.10.3 requires Livewire 4.4.7 or later, `ext-intl` and about ten other packages.
- Horizon 5.50.1 supports Laravel 13. Its UI is a precompiled Vue 3 SPA with Bootstrap 5.1, whose `dist/` is committed.
- Telescope 5.26.0 supports Laravel 13. Its UI is still Vue 2.7, with Bootstrap 4 and jQuery, although Vue 2 reached its end of life at the end of 2023: the cost of a precompiled UI nobody has time to rewrite.
- Reverb 1.12.0 supports Laravel 12 and 13.

| Option | Weight on applications | Authorization | Realtime | Fits the stack | Maintenance (1–2 people) | Use for the pilot | JSON reuse |
|---|---|---|---|---|---|---|---|
| **A. Blade and Livewire in the package**, like Pulse | Livewire required (or suggested, and the dashboard breaks without it) | Gate, easy | `wire:poll` | Low: Blade and Livewire where the stack writes React 19 and TypeScript | Medium: PHP only, but a second UI technology to keep up | Works, foreign to its front | No, unless an API is written as well |
| **B. Precompiled SPA**, like Horizon and Telescope | None at runtime: assets served by the package, no build in the application | Gate on every route, like `viewHorizon` | Polling; Reverb later | High: React 19, TypeScript, Vite, `@panneau/ui` tokens possible | A Node toolchain and a committed `dist/`; ages if left alone (Telescope) | Works without touching its front or its Panneau | Yes: the SPA is a client of a JSON API |
| **C. A Panneau 5 page or plugin** | Panneau 5 required, with its build in the application | `viewPanneau`, then a gate of its own | TanStack Query polling | Highest | Low for the UI, but waits for Panneau: pages are in its betas, widgets in 5.x | Works, inside the editors' admin | Yes |
| **D. Filament 5** | Filament, Livewire 4, `ext-intl` and their dependencies | Panel auth | Polling widgets | None: a second admin framework next to Panneau | High: two major upgrades between 3.0 (August 2023) and 5.0 (January 2026) | No | No |
| **E. A separate package** (`laravel-mediatheque-dashboard`) | Only for applications that install it | As B | As B | As B | One more repository, CI and release line, but free of 2.0's freeze | As B | As B |
| **F. Pulse cards and a recorder** | Pulse and Livewire; MySQL, MariaDB or PostgreSQL | `viewPulse` | `wire:poll` | Low | Small: a recorder and a few cards | Only if it installs Pulse, which no Folklore project uses | No |
| **G. A shared interface with Eclair's dashboard** | — | — | — | — | Eclair's dashboard is planned after its 1.0, technology not chosen; it is cross-project, behind an admin token, on Node | Not before Eclair 1.0 | — |

E is not a technology but where B lives. A separate package for the dashboard alone adds a repository and a release line for little gain: if the dashboard ever leaves this package, it becomes a Panneau 5 add-on (C) instead. F is good at what Pulse does, aggregates next to the application's other metrics, and poor at drilling into a run or acting on it. G already shares what matters most: run and task statuses and error codes are the same on both sides ([0012](0012-pipeline-runs-recorder-and-probe.md)).

## Decision

**A precompiled React SPA, shipped in this package, over a read model and actions of the package.** Three layers, all in this repository.

### 1. Data, read model and actions

- **Collect what is missing**, in alpha 3, before the beta's upgrade migration settles the schema:
  - **Every probe is a run** of the `probe` pipeline, synchronous ones included, created when it starts and completed through the recorder. This amends [0012](0012-pipeline-runs-recorder-and-probe.md), where only a pending probe is. Probe durations, attempts and errors live where every other run's do.
  - **`queued_at` on `pipelines_jobs`**, set when the driver dispatches or submits the rendition.
  - **`reason` and `ended_at` on `uploads`**: `aborted`, `expired`, or the `RejectionReason` of a rejected file.
  - **An `IngestRejected` event**, with the reason, for ingests that write no row.
  - **Indexes** on `ended_at` and `created_at` of `pipelines` and `pipelines_jobs`.
- **Pruning runs:** `mediatheque:prune` deletes ended runs older than `prune.runs_after_days`, and keeps each media's last run of each pipeline, so the dashboard and a media's history always show why it is in its current state. The default is longer than the 24 hours Eclair retries its webhooks for, since a late webhook needs its run.
- **A read model**, `Folklore\Mediatheque\Monitoring\`, through the model contracts:
  - query objects for runs, renditions, media and uploads, with filters, cursor pagination and a period;
  - an overview, failures grouped by code, durations and throughput over a bounded period;
  - results as readonly value objects;
  - tenant-agnostic: an application narrows every query with `Mediatheque::monitoringScope(fn (Builder $query, string $model) => …)`, as it chooses an upload's attributes ([0015](0015-new-install-schema-attributes-and-uploads.md)).
- **JSON resources** for runs, renditions and uploads, next to the media's ([0010](0010-json-resource.md)), so the dashboard, an application's own API and Panneau read the same shape. They are reviewed with Panneau 5, like `MediaResource`.
- **Actions in the `Mediatheque` contract**, next to `runPipeline()`: `retry(PipelineRun)`, `cancel(PipelineRun)`, `refresh(PipelineRun)` and `probeAgain(Media)`. The alpha 3 commands (`mediatheque:run-pipeline`, `mediatheque:refresh`) and the dashboard call them.
- **`mediatheque:status`**: the overview and the latest failures in the terminal. Cheap, useful as soon as alpha 3 ships, and it tests the read model before any UI.

### 2. HTTP and authorization

- **JSON endpoints for the dashboard only**, like `/horizon/api`, built from the read model and the resources. They are not a public, versioned API: an application that wants the same data in its own front uses the read model and the resources in its own routes, under its own policies.
- **Opt-in route**, as every route of the package ([0008](0008-secure-http.md)): the application calls `Route::mediathequeDashboard()` in its routes file, inside its own middleware (`web`, `auth`). The package's tests keep asserting that a fresh install exposes no route.
- **Two gates:**
  - `viewMediathequeDashboard` for every read;
  - `operateMediathequeDashboard` for the actions, which are `POST` requests with CSRF protection.
  - **Open in the `local` environment and closed everywhere else** until the application defines them, as Horizon and Pulse do. The install command writes a stub of both.
- **The data is cross-tenant** unless `monitoringScope()` narrows it. Error messages and paths are shown as stored; file URLs go through `temporaryUrl()`, for users who pass the gate only.

### 3. UI: a precompiled SPA

- React 19, TypeScript and Vite, in a `dashboard/` folder of this repository. The bundle is built in CI, committed in `dist/` and served by the package: nothing to install or build in the application, nothing added to its front. `dashboard/` is left out of the Composer archive (`export-ignore`); `dist/` is not.
- `@panneau/ui` primitives and tokens (light and dark themes, chart colors), bundled at an exact version, alpha included: the dashboard looks like the stack, whatever Panneau version the application runs, if any.
- **Realtime by polling**: every few seconds on active runs, slower elsewhere, with a cursor on `updated_at`. No SSE, which holds a PHP worker per open tab. Broadcasting the package's events over Reverb can come later if polling isn't enough.

### What stays outside

- **Panneau 5** shows a media's status and the error of its last run to editors, through `MediaResource`, and can link to the dashboard. It gets no monitoring page.
- **Pulse:** after 2.0.0, an optional recorder for applications that run Pulse, if one asks. The same goes for an aggregates table, if the tables stop being enough.
- **Eclair:** its dashboard reuses the vocabulary, and the dashboard's components if the technology fits once Eclair picks one. On this side, `laravel-eclair` can add what it knows (webhook deliveries, usage) to the run view later.

## Consequences

- Applications without the dashboard still gain the read model, the actions, the resources and `mediatheque:status`, and their failures get a recorded cause, probes included.
- Every probe writes a run: one or two more rows per media, and a failed media always has a run that says why. Pruning keeps the tables bounded.
- The read model and the resources are public API of 2.0, frozen at the release candidate with the rest. The dashboard's HTTP endpoints and its UI are not: they keep changing in 2.x minors.
- This repository gains a Node toolchain (Node 24, as the stack) and a committed `dist/`, with a CI job that builds the dashboard, runs its tests and checks that `dist/` matches its sources. Updating React and `@panneau/ui` is part of the package's releases: that is how the dashboard avoids aging like Telescope.
- The bundle follows `@panneau/ui`'s alphas at an exact version, so a breaking alpha of Panneau only costs an update of the dashboard, never a broken page in an application.
- Until the dashboard ships, `mediatheque:status` and the read model are the only views. That is enough to debug alpha 3.
- If the dashboard ever leaves this package, it becomes a Panneau 5 add-on over the same read model, not a standalone package.
