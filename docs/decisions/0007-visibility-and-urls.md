# 0007: Per-media visibility and temporary URLs

- Status: accepted
- Date: 2026-10-15

## Context

1.x URLs are always public: a source's URL prefix or the disk's `url()`. Applications that sell or restrict content need private media, played through short-lived URLs, while public media stay cacheable behind a CDN.

## Decision

- Each media has a **`visibility`**: `public` or `private`, from `ingest.visibility` by default. Its files follow it on their source (object ACL on S3-compatible disks). Changing it updates the files in a queued job.
- `url()` returns a public URL for a public media, and a **temporary URL** for a private one, with the configured lifetime. `temporaryUrl()` sets the lifetime explicitly.
- Disks that sign URLs (S3) sign them. Local disks sign them through Laravel's `serve` option (amended on 2026-10-23: the package has no streaming route of its own, since Laravel 12+, its floor, signs local temporary URLs).
- CDNs that sign their own URLs plug in through a **`UrlSigner`** contract.
- Private HLS (signed segments) is designed in the beta, with the HLS rendition: playlist rewriting through a signed route, or CDN token signing.
- Access control stays in the application: the package signs URLs; deciding who gets one is the application's policy.

## Consequences

- 1.x media are `public` after the upgrade, so their URLs don't change.
- A page that renders a private media must not be cached longer than its URLs live.
- `laravel-image` 2 resolves private images through the mediatheque and signs its own URLs.
