# 0018: The package ingests files; applications take uploads

- Status: accepted
- Date: 2026-10-21
- Amends: 0003, 0006, 0015, 0017
- Replaces: 0016

## Context

[0006](0006-ingest-and-uploads.md) gave the package optional upload adapters: presigned multipart uploads in the first alpha, and a TUS adapter later. [0015](0015-new-install-schema-attributes-and-uploads.md) and [0016](0016-uploads-signed-for-uppy-6.md) built the presigned one: an `uploads` table, routes behind `Route::mediatheque()`, signing for Uppy 6, and completion that ingests the file.

Not every file a user uploads is a media. An import, a file field or an attachment needs the same direct upload to storage, and an application with no media library needs it too. Panneau 5, the admin of the applications that use this package, now owns direct uploads ([Panneau's decision 0025](https://github.com/folkloreinc/panneau-js/blob/v5.x/docs/decisions/0025-presigned-uploads.md), [laravel-panneau#81](https://github.com/folkloreinc/laravel-panneau/issues/81)). Its uploads are not media: values reference them, and one of their uses is to create a media.

Two upload systems, one in each package, would mean two tables, two signing rules and two policies for the same browser request. They would also put upload endpoints in two places, which [0009](0009-package-boundaries.md) set out to avoid.

## Decision

- **The package ingests files. It doesn't take uploads.** It has no upload routes, no `uploads` table and no upload model. Uploads belong to the application, or to Panneau, which signs them, tracks them and authorizes them.
- **A file on a disk is a source like any other.** `Mediatheque::ingest(Source::disk($disk, $path, $filename))` ingests a file that the browser uploaded directly to storage. When the disk is the media's, the file is moved or copied without going through PHP, and the original gets the type detected from its content. This is how an application, or Panneau's `POST /media` with an upload, turns an upload into a media.
- **The application chooses the media's attributes** where it calls `ingest()`: `attributes`, `visibility`, `pipeline` and `data`, from its own context. `Mediatheque::uploadsUsing()` is gone with the routes that used it.
- **Removed:**
  - `Route::mediatheque()` and the upload routes;
  - `Upload`, `UploadStatus` and `UploadResource`;
  - `Folklore\Mediatheque\Uploads\*`;
  - the `uploads` config and the `upload` model key;
  - the uploads of the monitoring read model (`Overview::$uploadsOpen` and `$uploadsEnded`, `Monitoring::uploads()`) and of `mediatheque:status`.
- **A migration drops the `uploads` table** that earlier alphas created. `mediatheque:install` publishes it like any later migration.
- **No TUS adapter.** An application that needs TUS runs its own server and ingests the file it receives.
- **Uids.** `upl_` is no longer a prefix of the package ([0003](0003-identifiers-and-references.md)).
- **Monitoring** covers media, runs and renditions ([0017](0017-monitoring-dashboard.md)). `Mediatheque::monitoringScope()` receives media queries only, with the kind `media`.

## Consequences

- An application that uploaded through `Route::mediatheque()` uploads through Panneau's routes, or its own, and calls `ingest()` with the file on the disk.
- Uploads that never become a media, such as imports, don't touch this package.
- The package has no HTTP route left except the monitoring dashboard's, which stays opt-in ([0008](0008-secure-http.md)).
- Size and type limits apply at ingest, as for any source. An application that wants to refuse a large file before it reaches the storage checks it where it signs the upload.
