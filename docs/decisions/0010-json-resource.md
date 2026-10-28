# 0010: A canonical JSON resource

- Status: accepted, amended on 2026-10-28 ([#133](https://github.com/folkloreinc/laravel-mediatheque/issues/133))
- Date: 2026-10-15

## Context

1.x has no JSON resource. The shape front ends consume comes from `laravel-folklore`'s `MediaResource`, and applications write their own variants. `files` is a list by default, while `panneau-js` reads it as an object keyed by handle, so video playback only works when the resource is asked for a map. Panneau 5's protocol defines how media items look in fields and lists.

## Decision

- The package ships **`MediaResource`** and **`FileResource`**, the canonical shape of a media.
- `id` is the `uid`. The resource includes `type`, `name`, `status`, `visibility`, `url`, `thumbnail_url`, `metadata` and `files`.
- **`files` is an object keyed by handle**; a group is a list under its handle.
- The shape follows Panneau 5's protocol for media items, and the two are kept in sync through shared fixtures.
- A **`legacy`** option emits the 1.x `laravel-folklore` shape (internal `id`, `files` as a list), for applications during their upgrade.
- Applications extend the resource to add fields, rather than writing their own.
- Amended on 2026-10-28 ([#133](https://github.com/folkloreinc/laravel-mediatheque/issues/133)), since Panneau 5 no longer reads `files` and laravel-panneau builds its `MediaInfo` itself, in camelCase:
  - **In sync with Panneau 5** means the same meaning in snake_case: the resource has the fields of Panneau 5's `MediaInfo`, and laravel-panneau only renames them. The resource adds `mime` and `size` (the original's), `width`, `height` and `duration` (from the metadata), `transform`, `error` and `processing`, and `updated_at`. The fixtures are this package's, in `tests/fixtures/json/`: a ready, a processing and a failed video, and an image. An adapter tests its mapping against them.
  - `url` is the file the media plays (`$media->url()`). `thumbnail_url` is that file for an image, and `thumbnail()`'s for other types; an application that wants a `laravel-image` preset overrides it.
  - `error` is `{code, message}` from the new `Media::failure()`, on the contract, while the media is `failed`: the first rendition of its latest unfinished run that failed for its own reason, or its probe's error. `processing` is `processing()` without the run's uid, while the media is `pending` or `processing`. Each costs a query for those media only.
  - `metadata` is the media's, without null values. `files` is an object keyed by handle, the original first, a group a list; `FileResource` has `handle`, `index` (in a group), `role`, `name`, `url`, `mime` and `size`, then `width`, `height`, `duration`, `bitrate` and `parts_count` when known, and no internal id. A URL the disk can't give is null.
  - **No `legacy` option.** `laravel-folklore` keeps its 1.x `MediaResource` and builds it from this package's contracts.

## Consequences

- `laravel-folklore` 2 (and a 1.x minor, if needed) delegates to this resource.
- Front ends that read `files` as a list move to the object shape, or use the `legacy` option until they do.
