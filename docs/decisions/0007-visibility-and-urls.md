# 0007: Per-media visibility and temporary URLs

- Status: accepted
- Date: 2026-10-15

## Context

1.x URLs are always public: a source's URL prefix or the disk's `url()`. Applications that sell or restrict content need private media, played through short-lived URLs, while public media stay cacheable behind a CDN.

## Decision

- Each media has a **`visibility`**: `public` or `private`, from `ingest.visibility` by default. Its files follow it on their source (object ACL on S3-compatible disks). Changing it updates the files in a queued job.
- Changing it (amended on 2026-10-28, [#119](https://github.com/folkloreinc/laravel-mediatheque/issues/119)) goes through `Mediatheque::setVisibility($media, $visibility)`, or `$media->setVisibility()`, never the column alone, which would leave the files as they are. The media's row is locked and read again; setting the visibility it has changes nothing. The media has its new visibility at once, so `url()` gives temporary URLs of a media made private before its files follow. Once committed, `MediaVisibilityChanged` is dispatched with the previous and the new visibility, and `ApplyVisibility` is queued: it reads the media's visibility when it runs, so a later change wins and a retry changes nothing, and applies it to every stored file, the original and the renditions. A file its disk no longer has is skipped; a file whose visibility can't be set fails the job, so a media made private never keeps a public file silently. A run writes its files with the visibility it started with: once the recorder records them, it reads the media's visibility again, after the commit, and queues the same job if it changed since.
- `url()` returns a public URL for a public media, and a **temporary URL** for a private one, with the configured lifetime. `temporaryUrl()` sets the lifetime explicitly.
- Disks that sign URLs (S3) sign them. Local disks sign them through Laravel's `serve` option (amended on 2026-10-23: the package has no streaming route of its own, since Laravel 12+, its floor, signs local temporary URLs).
- A temporary URL can download its file under a name (amended on 2026-10-26, [#102](https://github.com/folkloreinc/laravel-mediatheque/issues/102)): `download: true`, or a file name, on `temporaryUrl()`. On a file, `true` is the file's name. On a media, `true` is the media's name with the extension of the file it serves (`episode-1` gives `episode-1.mp4`; a name that already ends with it is kept), or the file's name when the media has none. S3-compatible disks sign it with `ResponseContentDisposition` (`attachment`, with `filename` and `filename*`, RFC 6266). Laravel's signed route of a local disk has no equivalent option, and the package has no route of its own, so a local disk ignores it: its URL shows the file. A `UrlSigner` will get the option when one is wired.
- CDNs that sign their own URLs plug in through a **`UrlSigner`** contract.
- Private HLS (signed segments) is designed in the beta, with the HLS rendition: playlist rewriting through a signed route, or CDN token signing.
- Access control stays in the application: the package signs URLs; deciding who gets one is the application's policy.

## Consequences

- 1.x media are `public` after the upgrade, so their URLs don't change.
- A page that renders a private media must not be cached longer than its URLs live.
- `laravel-image` 2 resolves private images through the mediatheque and signs its own URLs.
