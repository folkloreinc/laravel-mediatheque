# 0016: Direct uploads signed for Uppy 6

- Status: accepted, amended by [0018](0018-direct-uploads-as-services.md)
- Date: 2026-10-21
- Amends: 0015

## Context

The direct uploads of alpha 1 ([0006](0006-ingest-and-uploads.md), [0015](0015-new-install-schema-attributes-and-uploads.md)) follow the API of Uppy's `AwsS3` plugin up to Uppy 5: the client calls one route per step, and the server creates the multipart upload, signs its parts, completes it, then ingests the file.

Uppy 6 (`@uppy/aws-s3` 6.0, 2026-08-26) rewrote the plugin. It runs its own S3 client and only asks the application to sign each request: `signRequest(request) → {url, key?, headers?}`. The browser sends `CreateMultipartUpload`, `UploadPart`, `ListParts`, `CompleteMultipartUpload` and `AbortMultipartUpload`, or a single `PutObject`, to the storage itself. The server never sees the upload complete, so nothing ingests the file. Since 6.1, the plugin uses the `key` the signer returns for the request that starts an upload.

Four things the server-side flow did must now happen another way: choosing the key, tying every later request to its upload and its user, knowing that the file is on the storage, and keeping the type the client sends out of the stored file.

## Decision

### `POST uploads/sign`

- The body is Uppy's `PresignableRequest`: `method` and `key`, and `uploadId`, `partNumber` and `contentType` when the request has them. The S3 operation follows from the method, the `uploadId` and the `partNumber`, as Uppy describes them; any other combination is refused (`422`, `reason: unknown_operation`).
- **A request that starts an upload** (`CreateMultipartUpload`: `POST` without `uploadId`; `PutObject`: `PUT` without `uploadId`) creates an `Upload` for the user, with its own key: `{uploads.path}/{upl_…}`, under the disk's root. The key the client proposed is ignored. The response is `{url, key}`: the presigned URL, and the key Uppy uses for the rest of the upload.
- **Any other request** is signed only for the whole key of an open upload of the same user. Anyone else's key, an unknown key or an aborted or completed upload gets a `404`, as on the other routes; an expired upload gets a `410`.
- **The first `uploadId` signed for an upload is recorded** (`provider_upload_id`), and requests that name another one are refused. One key has one multipart upload, and `mediatheque:prune` can abort it.
- Signing changes no status. An abort that is signed may never be sent: an upload left open expires and is pruned.
- URLs live `uploads.url_ttl` seconds. Uppy's `expiresIn` is ignored.
- Every request is authorized with the `create` ability of the media policy, as on the other routes. Errors are `application/problem+json`.
- **The upload's id is the last segment of its key.** The route names uploads by key only, because Uppy only ever sends the key it received, and checks the whole key. Accepting the id as well would add a second way to name an upload without making any check stronger.

### Completion

- `POST uploads/{upload}/complete` **without `parts`** ingests a file the client already completed on the storage, or sent with one `PutObject`. The file must be on the upload's disk; otherwise the route answers `409` (`reason: incomplete`), and the client can complete it later.
- With `parts`, the server completes the multipart upload, as before. An upload with no recorded multipart upload answers `409` (`reason: not_multipart`).
- `filename` names the media when the upload has no file name yet, which is the case of every upload started by `uploads/sign`.

### Type and size

- The AWS SDK never signs `Content-Type` in a presigned URL, so the client chooses the type of the object it sends. **Ingest replaces it**: the copy of a file from the same disk gets the type detected from its content (`MetadataDirective: REPLACE`), for both flows.
- A request that starts an upload doesn't declare the file's size. The size is checked when the upload completes, against the type's `max_size`, by the ingest step; a rejected file is deleted. A client's own restrictions (Uppy's `maxFileSize`) are the early guard.

### Routes under a prefix

The actions read the `{upload}` parameter by name. An application can put the routes under a prefix with its own parameters (`workspaces/{workspace}/uploads`), which Laravel passes to an action before the route's own ones.

### Storage

The bucket's CORS rules allow `PUT`, `POST`, `GET` and `DELETE` from the application's origin, allow the `Content-Type` header, and expose `ETag`.

## Consequences

- Both Uppy 5 and Uppy 6 work, on the same `uploads` table, policy and ingest. Uppy 6 needs `@uppy/aws-s3` 6.1 or later, which uses the key the server returns.
- With Uppy 6, the client completes the upload on the routes after Uppy's `upload-success` event, with the id from the key and the file's name.
- The original of a media ingested from the same disk has its detected type on the storage, rather than the type it was uploaded with.
- Each request that starts an upload creates a row, as `POST uploads` does. Rows of uploads that never complete expire.
