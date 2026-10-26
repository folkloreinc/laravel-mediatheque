# 0015: The schema of a new install, application attributes, and uploads

- Status: accepted, amended by [0016](0016-uploads-signed-for-uppy-6.md) and [0018](0018-ingest-only.md): uploads left the package; amended on 2026-10-26: placeholders read accessors (#64)
- Date: 2026-10-16

## Context

Alpha 1 is installed by projects that start on 2.0: they have no 1.x tables. The conversion of 1.x tables ([0002](0002-evolve-the-schema-in-place.md)) comes with `mediatheque:upgrade`, in the beta.

The package is tenant-agnostic ([0009](0009-package-boundaries.md)): an application adds its own columns (a tenant id) and passes them to the path formatter (`{media.tenant_id}`). The path is built before the media is saved, so these columns must be set before Eloquent's `creating` event.

Presigned multipart uploads ([0006](0006-ingest-and-uploads.md)) take several requests: create the upload, sign its parts, complete it. Each request is authorized by the application's `Media` policy ([0008](0008-secure-http.md)), but the policy alone can't tell whether the request that completes an upload comes from whoever created it.

## Decision

### Migrations

- `mediatheque:install` publishes the config and the migrations, with fresh timestamps. The package never loads migrations itself (#22). Running the command again publishes only the migrations the application doesn't have yet, so a later alpha adds its own.
- A new install creates the 2.0 tables under their 1.x names, with the table prefix: `medias`, `files`, `pipelines` and `pipelines_jobs`, and the new `uploads`. `medias_pivot` comes with `HasMedias` (alpha 2). The tables only 1.x used (`files_pivot`, `metadatas`) are not created.
- The beta's upgrade migration converts 1.x tables to the same columns, so an upgraded project and a new one end with the same schema.
- Columns:

  | Table | Columns |
  |---|---|
  | `medias` | `id`, `uid` (unique), `type`, `name`, `status`, `visibility`, `metadata` (JSON), `data` (JSON), `derived_from_id` (nullable, null on delete), timestamps, `deleted_at` |
  | `files` | `id`, `media_id` (cascade), `handle`, `index` (nullable), `role`, `source`, `path`, `name`, `mime` (255), `size` (64-bit), `checksum`, `width`, `height`, `duration` (double), `bitrate` (64-bit), `parts_count`, `metadata` (JSON), `definition_hash`, `pipeline_job_id` (nullable, null on delete), `provider`, `provider_ref`, timestamps |
  | `pipelines` | `id`, `uid` (`run_…`, unique: the run's key), `name`, `pipelinable` (morph), `transcoder`, `external_id` (indexed with `transcoder`), `definition` (JSON), `output_source`, `path_prefix`, `visibility`, `status`, `progress`, `attempts`, `error` (JSON), `started_at`, `ended_at`, timestamps |
  | `pipelines_jobs` | `id`, `pipeline_id` (cascade), `name` (the rendition, unique in its run), `status`, `progress`, `attempts`, `error` (JSON), `reason`, `started_at`, `ended_at`, timestamps |
  | `uploads` | `id`, `uid` (`upl_…`, unique), `status`, `disk`, `path`, `provider_upload_id`, `filename`, `size`, `creator` (nullable morph), `media_id` (nullable, null on delete), `expires_at`, timestamps |

- Applications add their own columns in the published migration, or in their own.

### Application attributes

- `IngestOptions` has **`attributes`**: columns of the application's media model, set before the path is built and the media saved. `Mediatheque::ingest()` takes them as a named argument (`attributes: ['tenant_id' => $tenant->id]`).
- The path formatter reads any attribute of the media (`{media.tenant_id}`). A placeholder that names a missing or empty attribute throws, rather than building a path without it.
- (Amended on 2026-10-26, [#64](https://github.com/folkloreinc/laravel-mediatheque/issues/64).) An attribute that no column has is read from the model's accessor, so a path can hold a tenant's public id rather than its sequential key: `{media.tenant_uid}` from a `tenantUid()` accessor that reads the tenant relation, whose foreign key `attributes` sets before the path is built. A column keeps its raw value, even when the model casts it or has an accessor of the same name, so the paths of existing media don't change.
- The upload routes get them from the application: `Mediatheque::uploadsUsing(fn (Request $request) => new IngestOptions(attributes: [...]))` returns the options of the media an upload creates, so the application decides them from its own context (the current tenant, a route parameter), never from fields the client sends.

### Uploads

- Every upload request is authorized with the policy's **`create`** ability on the media model. Without a policy, every request is denied.
- An upload records its **creator**, the authenticated user of the request that created it. Only that user can sign its parts, complete it or abort it; anyone else gets a 404, as if the upload didn't exist. A guest can't create an upload, even if the policy allows guests.
- The client declares the file's name and size when it creates the upload. The size is checked against the configured limits then, and again on the stored object when the upload completes. The type is detected from the content when it completes, by the ingest step: a rejected file is deleted from the disk.
- Completing an upload ingests the file from its disk (`Source::disk()`), moved to its final path without going through PHP. The response gives the media's `uid`, `type` and `status`. The probe and the pipeline run in the queue.
- Uploads expire (`uploads.expires_after`, 24 hours by default). `mediatheque:prune` aborts expired uploads and removes their parts (alpha 2).

## Consequences

- A project that starts on 2.0 runs `mediatheque:install`, then `migrate`, and owns its schema from the start.
- Media created through uploads get the application's tenant column and path, chosen by the application on the server. A client can't upload into another tenant by changing a request field.
- An upload is tied to a user: an application whose users upload from several devices completes each upload from the device that started it, which is what upload clients do.
- The checksum of an original ingested from a disk is computed by the probe job, which reads the file anyway ([0013](0013-media-resolver-for-laravel-image.md)), so completing a large upload doesn't read it in the request.
