# 0009: Package boundaries

- Status: accepted
- Date: 2026-10-15

## Context

2.0 is designed together with `laravel-image` 2, Eclair, Panneau 5 and, later, `laravel-folklore` 2, with the same pilot project. Each of them touches media. Without clear boundaries, features end up in two places (image resizing, upload endpoints, tenant scoping).

## Decision

| Concern | Owner |
|---|---|
| Ingest, storage, probe, renditions, metadata, visibility, references | `laravel-mediatheque` |
| Image renditions on the fly (presets, crops, formats, responsive markup) and their URLs | `laravel-image` 2, which resolves a media id through a resolver this package provides |
| Shared processing server | Eclair, as a `Transcoder` driver in `laravel-eclair` |
| AI operations (transcription, alt text, highlights) | A transcoder that supports them (Eclair). This package knows no AI provider |
| Admin media API and UI | Panneau 5, through an adapter over this package |
| Entities and resources of Folklore sites | `laravel-folklore`, which uses this package's JSON resource |
| Tenancy, users, policies, the application's own API | The application |

- **Tenant-agnostic.** The package has no tenant, user or organisation concept. An application adds its columns in its own migrations, extends the models, adds scopes, and passes its attributes to the path formatter.
- Image processing that must happen once, at ingest (HEIC to a web format, orientation, removing GPS data), belongs to this package. Everything that can be rendered on the fly belongs to `laravel-image`.
- "HEIC to a web format" means a web version produced at ingest, the original kept (amended on 2026-10-28, [#132](https://github.com/folkloreinc/laravel-mediatheque/issues/132)), so a HEIC shows in every browser whatever the servers that render images can read. The default `image` pipeline has one rendition, `web`, its main rendition: a JPEG of the same size, upright, with the color profile and no other metadata, from the `ffmpeg` driver's `image.convert` operation (Imagick), for HEIC, HEIF and AVIF only (`when.mime`). The media plays it (`url()`, the `media` resolver's `med_…`) instead of its original, which stays available by handle. Other images run nothing, as before: sizes, crops and formats stay `laravel-image`'s, on the fly.

## Consequences

- The package keeps working without `laravel-image`, `laravel-eclair` or Panneau: they are suggested, not required.
- Features that cross a boundary are designed in both repositories, with linked issues.
