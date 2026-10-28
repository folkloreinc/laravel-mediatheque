# 0006: One ingest step, with optional upload adapters

- Status: accepted, amended by [0018](0018-ingest-only.md): the package has no upload adapters
- Date: 2026-10-15

## Context

In 1.x, files reach the package through an upload controller (or an application's own), and metadata is read synchronously during the request. Large videos and phone uploads need resumable uploads that don't go through PHP. Applications also need to ingest files that are already on a disk, or to import from a URL (the 1.x `pull` route was never implemented).

Three options were considered: an ingest API only, a built-in TUS server, or an ingest API with optional adapters.

## Decision

- **The core is `Mediatheque::ingest()`**, which takes an uploaded file, a local path, a stream, a file already on a disk, or a URL. It validates the content, stores the original, creates the media and queues the probe and the pipeline. Every other way in ends there.
- Validation reads the content: the type comes from the sniffed mime, the extension from the type, never from the client's file name. SVG is sanitized. Each type has a maximum size and allowed mimes.
- The location is removed from images before they are stored (amended on 2026-10-28, [#120](https://github.com/folkloreinc/laravel-mediatheque/issues/120)), unless `ingest.images.strip_location` is false: the EXIF GPS IFD of JPEG, PNG, WebP, TIFF, HEIF and AVIF images, and their XMP properties named `GPS…` in any namespace. The package does it in PHP, without a binary and without re-encoding: the bytes are blanked where they are, so the image keeps its length, its pixels and its other metadata (the orientation, the camera, the date), and the stored checksum is the stripped file's. exiftool and Imagick were considered: exiftool is rarely installed on application servers, and Imagick can't remove one tag without re-encoding the image. An image on a disk is downloaded to be read whole, and is still copied from its disk when it has no location.
- URL imports run in a queued job behind an SSRF guard: public addresses only, checked redirects, size and time limits.
- **Optional upload adapters**, on opt-in routes:
  - **presigned multipart uploads** to S3-compatible disks (Uppy's `AwsS3` plugin), in the first alpha;
  - **a TUS adapter** in a later alpha, for clients that need TUS.
- Uploads are tracked in an `uploads` table with an expiry. `mediatheque:prune` cleans up abandoned ones.

## Consequences

- Large files go from the client to the storage directly; PHP only signs requests and ingests the result.
- The probe no longer runs in the upload request. A media is `pending` until it has been probed.
- Applications that upload through their own controllers call `ingest()` and keep their routes.
- The TUS server library is chosen when its alpha starts.
