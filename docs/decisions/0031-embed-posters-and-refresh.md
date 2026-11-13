# 0031: An embed's poster, and its refresh

- Status: proposed
- Date: 2026-11-13
- Issue: [#170](https://github.com/folkloreinc/laravel-mediatheque/issues/170)
- Amends: [0029](0029-embeds.md)

## Context

An embed is a media whose original is a platform's page ([0029](0029-embeds.md)). Its probe gives the provider's thumbnail as a URL, `thumbnail_url`, which the JSON resource shows. That URL lives at the provider. Instagram's and TikTok's expire within days, and a thumbnail can change or disappear with the video. Applications want a poster stored with the media, as a video has, which `laravel-image` can resize.

What a provider says also changes after the probe: the title, the privacy, whether the video still exists. An embed probed once never learns it.

A pipeline's renditions read the original's file, and an embed has none. A job of Eclair has one input, which for an embed is its page (`{ "embed": … }`): a `{ "url" }` input of the thumbnail can't run in the same job, and would need each CDN of each provider in the project's `inputHosts`.

## Decision

- **`embed.thumbnail`**, an operation of Eclair ([urbania-media/eclair#116](https://github.com/urbania-media/eclair/issues/116)) and of the `ffmpeg` driver, writes the provider's thumbnail, for the renditions that read from it. It reads no file.
  - Eclair reads the thumbnail's URL from its provider's answer, and downloads it through its SSRF guard.
  - The `ffmpeg` driver reads `thumbnail_url` from the media's metadata. It downloads it only from the provider's thumbnail domains (`EmbedProviders::THUMBNAIL_DOMAINS`), over https, without following redirects, up to 20 MB, within `embeds.timeout`. A JPEG is kept as it is; a PNG, WebP or GIF becomes a JPEG; anything else fails.
  - An operation of the `ffmpeg` driver that reads no file implements `ReadsNoFile`: its rendition runs on a remote original, with no input.
  - No thumbnail fails with `provider_unavailable`. The poster isn't the main rendition, so the embed stays `ready`.
- **The `embed` pipeline** is the embeds' pipeline by default (`embeds.pipeline`): `thumbnail` (`embed.thumbnail`, not kept), then `poster` (`image.convert` to JPEG, `from: thumbnail`, role `poster`). The poster is a file of the media like a video's: its ThumbHash, its URL, and the resource's `thumbnail_url`.
- **A refresh** probes a ready embed again: `ProbeMedia` with `refresh`, recorded as a run of the `probe` pipeline marked `refresh` in its snapshot, so its duration, attempts and error are recorded, and a pending probe completes through the recorder as any other.
  - The recorder gives its outcome to `EmbedRefreshes` instead of `ProbeResults`. A refresh never moves the media back to `pending` or `failed`.
  - Done: what the provider says now replaces what it said, the original gets the new dimensions and duration, and `EmbedRefreshed` is dispatched. When the thumbnail changed (its URL without the query, which some providers sign at each request), or the embed has no poster, the embeds' pipeline runs again, forced.
  - `provider_unavailable` (deleted, private, not embeddable): `unavailable_at` is set in the metadata, once, and `EmbedUnavailable` is dispatched. The media keeps its status and its files, and the resource's `embed.available` is false. A later refresh that finds it removes the key.
  - Any other failure (a quota, a provider down) changes nothing: the run records it, and the next refresh tries again.
- **`mediatheque:refresh-embeds`** queues the refresh of ready embeds that no probe has read for `--days` (7), the least recently probed first, `--limit` (100) at a time, so a schedule spreads the requests to the providers and their quotas. `--media` names some, whenever they were probed. The application schedules it.

## Consequences

- An embed has a poster without any setting, on the `ffmpeg` driver (with Imagick) or on Eclair. An application that wants none sets `embeds.pipeline` to null.
- On Eclair, the `embed` pipeline needs `embed.thumbnail` on the server and in `laravel-eclair`.
- An application decides what an unavailable embed becomes: hidden, flagged to its owner, or deleted. The package doesn't delete or fail it, since a video made private can come back.
- The `ffmpeg` driver requests the providers' CDNs from the application's servers, as `OEmbedProber` requests their oEmbed endpoints. A new provider adds its thumbnail domains.
