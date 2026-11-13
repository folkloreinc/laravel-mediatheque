# 0029: Embeds, media whose original is a platform's page

- Status: proposed
- Date: 2026-11-13
- Issue: [#170](https://github.com/folkloreinc/laravel-mediatheque/issues/170)
- Amends: [0004](0004-renditions.md), [0006](0006-ingest-and-uploads.md), [0012](0012-pipeline-runs-recorder-and-probe.md)

## Context

Applications keep videos and audio that live on platforms (YouTube, Vimeo, Dailymotion, SoundCloud, Spotify, TikTok, Instagram) next to their own media. Today they keep a bare URL in a field, or a 1.x media without a file, with the embed in its `data`. They want what they get for their other media: a media they reference from rich text, with its metadata (title, author, duration, dimensions), a thumbnail, and later its file.

2.0 already has the columns of a remote file, `files.provider` and `files.provider_ref` ([0004](0004-renditions.md)), for files that live with a provider. No driver used them yet. Eclair reads embeds by the URL of their page, through the providers' APIs and oEmbed ([urbania-media/eclair, decision 0022](https://github.com/urbania-media/eclair/blob/main/docs/decisions/0022-embeds.md)), and downloads them when a project allows it.

## Decision

- **`Source::embed($url)`** ingests an embed: a media whose original is remote. Its `provider` is the platform, its `provider_ref` the id at the platform, and it has no source and no path. The original keeps the canonical URL of the page in its metadata (`provider_url`).
- **Nothing is requested at ingest.** The URL is matched against the providers of `mediatheque.embeds.providers` (the seven above by default), with the same rules as Eclair's providers, so a page has the same provider, id and canonical URL whichever prober reads it. Any other URL is rejected with `RejectionReason::EmbedNotAllowed`.
- **Its type is its provider's**: `audio` for SoundCloud and Spotify, `video` for the others. The media starts with the provider's keys in its metadata.
- **The probe reads the provider**, through the `Prober` contract, which gets the remote original:
  - the local prober hands remote originals to `OEmbedProber`, which calls the provider's oEmbed endpoint, or Dailymotion's public API, and never the page itself: no discovery, and no request to an address the URL chooses. It needs no credentials, so it gives no YouTube duration and no Instagram embed;
  - Eclair's prober (in `laravel-eclair`) calls Eclair's `embed.probe`, which reads the providers' APIs with the project's credentials.
- **Canonical metadata keys** for embeds: `provider`, `provider_id`, `provider_url`, `embed_url` (the player, for an iframe), `embed_html` (oEmbed's only), `title`, `description`, `author_id`, `author_name`, `author_url`, `thumbnail_url`, `published_at`, `embeddable`, `privacy` and `live`, with `kind`, `duration`, `width` and `height` as for any media. The probe's `mime` is `text/html`, the page's. `TranscoderErrorCode` gains Eclair's `provider_unavailable` and `provider_refused`.
- **An embed runs no pipeline by default**: the type's pipeline would read a file it doesn't have. Once probed, it is `ready`, and plays from its platform. `mediatheque.embeds.pipeline` names a pipeline for embeds, for renditions that don't read the original's file.
- **Applications read it** with `Media::isEmbed()` and `Media::embedUrl()`, and `File::provider()` and `File::providerRef()` on its original. The JSON resource has an `embed` object (`provider`, `url`, `embed_url`), and its `thumbnail_url` falls back on the provider's thumbnail.

## Consequences

- An embed is a media like the others: referenced, used, made private, deleted. Its URL never decides where the server connects: only the providers' endpoints are requested.
- The contracts grow: `File::provider()`, `File::providerRef()`, `Media::isEmbed()` and `Media::embedUrl()`. An application's own implementations add them (alphas can break, [0001](0001-supported-versions.md)).
- Without Eclair, an embed's metadata is what oEmbed gives: no YouTube duration, no author id for most providers, nothing for Instagram. With Eclair and its project's credentials, the APIs give the rest.
- A poster stored with the media, copied from the provider's thumbnail, needs a rendition that reads another input than the original's file; it comes with a refresh command that probes embeds again (title, privacy, deletion), in a later change of #170. Importing an embed's file comes with [#171](https://github.com/folkloreinc/laravel-mediatheque/issues/171).
