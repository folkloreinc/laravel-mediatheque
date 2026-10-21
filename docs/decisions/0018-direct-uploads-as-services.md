# 0018: Direct uploads as services

- Status: accepted
- Date: 2026-10-21
- Amends: 0016

## Context

The direct uploads of [0015](0015-new-install-schema-attributes-and-uploads.md) and [0016](0016-uploads-signed-for-uppy-6.md) were only reachable through the package's routes. Their rules lived in the controller: the policy, the owner of an upload, the first multipart upload of a key, the completion and the ingest.

Panneau 5 offers the same flow under its own API ([panneau-js#82](https://github.com/folkloreinc/panneau-js/pull/82), Panneau's decision 0025): `POST uploads/sign` and `POST uploads/{upload}/complete`, implemented by `laravel-panneau`. It keeps uploads apart from media: completing an upload gives the upload, and a media is made from it later (`POST /media` with `{upload}`), or the upload is used by something else, such as an import. To do that, `laravel-panneau` has to call this package's uploads, storage and ingest without its routes, and without copying their rules.

Clients also had to read the upload's id from the last segment of the key to complete it, which ties them to the key's format.

## Decision

### The sign response names the upload

A request that starts an upload (`CreateMultipartUpload`, or `PutObject` without `uploadId`) answers **`{url, key, upload}`**, where `upload` is the id of the upload it created (`upl_…`). Other requests answer `{url, key}` as before. Uppy ignores the extra member; a client keeps it to complete the upload. The key's format doesn't change, so clients that read the id from the key keep working.

### `DirectUploads`

`Folklore\Mediatheque\Uploads\DirectUploads`, resolved from the container, does what the routes do. Every method takes the user, so no caller can skip a rule:

| Method | What it does |
|---|---|
| `sign($user, $method, $key, $uploadId, $partNumber)` | Presigns one S3 request → `SignedRequest` (`url`, `key`, `upload`, `created`; `toArray()` is the route's response) |
| `find($user, $upload, completed: false)` | An open upload of the user, by id or model |
| `findByKey($user, $key, $uploadId)` | The open upload of the user with this key, and this multipart upload |
| `key($upload)` | The key of an upload in its bucket |
| `complete($user, $upload, $parts, $filename)` | Makes sure the file is on the storage, assembling the parts when they are given; doesn't ingest |
| `ingest($user, $upload, $options)` | Ingests the file into a media, and completes the upload |
| `create()`, `signParts()`, `parts()`, `abort()` | The server-side multipart steps of Uppy 5, and abort |

- **Same rules as the routes.** Every method authorizes the user with the `create` ability of the media policy (`AuthorizationException`). An upload is the user's who created it: for anyone else, it doesn't exist.
- **Errors are exceptions**, which the routes turn into problems: `UploadNotFound` (`404`), `UploadExpired` (`410`), `UploadIncomplete` with its `reason`, `incomplete` or `not_multipart` (`409`), `UnknownS3Operation` (`422`, `unknown_operation`), and `FileRejected` from ingest (`422`, with the rejection's reason).
- **Completion and ingest are two steps.** `complete()` checks the file, records the name the client gives and the size of the stored file when the upload has none, and leaves the upload open. `ingest()` refuses a file that isn't on the storage yet (`UploadIncomplete`) rather than rejecting it, so the client can complete it later. It deletes a file that ingest rejects and aborts the upload with the reason, as before. A completed upload gives the media it created.
- **The options of the media** are those of `Mediatheque::uploadsUsing()` for the current request, unless the caller passes its own `IngestOptions`. Those must come from what the application decides, never from fields the client sends.
- **The routes are an HTTP layer** over these services: they validate the request, call the services and format the response or the problem. Their behavior doesn't change.
- `DirectUploads` is a class rather than a contract: its rules are the package's, and an application that needs another flow writes its own routes on `MultipartStorage` and the ingest.

## Consequences

- `laravel-panneau` implements Panneau's `uploads/sign` with `sign()` and `toArray()`, `uploads/{upload}/complete` with `complete()`, and `POST /media` from an upload with `ingest()`, behind its own middleware, with the same policy, owners and media options as the package's routes.
- An upload that is completed but not ingested stays open: it expires, and is pruned, unless it is ingested or aborted first.
- Aborting an upload that has no multipart upload, as uploads started by `uploads/sign` with `PutObject`, deletes its file instead of failing.
