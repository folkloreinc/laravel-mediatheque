# 0013: The `media` resolver for `laravel-image`

- Status: accepted
- Date: 2026-10-16

## Context

This package ships the `media` resolver of `laravel-image` 2, its `SourceResolver` contract ([0009](0009-package-boundaries.md), and [`laravel-image`'s decision 0006](https://github.com/folkloreinc/laravel-image/blob/v2.x/docs/decisions/0006-sources-and-resolvers.md)). The [review of the resolver](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071) found eight points where the two packages diverged. Three of them block alpha 1 on both sides:

1. **Version.** `laravel-image` puts a source version in every URL. A media has none, and `replaceOriginal()` keeps the `uid`, so a replaced image would keep its URLs.
2. **Which file a key names.** The URL key is the media's `uid`, so it names a media. Video posters and thumbnails are files of their media, and go through `laravel-image` like images.
5. **Disk.** An `ImageSource` carries a Laravel disk and a path. A 1.x source can be a local directory, not a disk.

`laravel-image` settled them in its [decision 0010](https://github.com/folkloreinc/laravel-image/blob/v2.x/docs/decisions/0010-media-resolver.md) (folkloreinc/laravel-image#69), as the review proposed, and left two points to this package's resolver, before its first alpha: what deleted, pending and failed media resolve to (7), and which reference forms `supports()` accepts (8). The other points (orientation, dominant color, focal point, `transform`, clearing derivatives) are left to [laravel-image#88](https://github.com/folkloreinc/laravel-image/issues/88), still open. One part of point 3 is settled here, because it names a canonical metadata key: the placeholder format.

## Decision

### Sources are disks

- **A mediatheque source is a Laravel disk name.** `mediatheque.source`, a pipeline's `source`, `IngestOptions::$source` and `File::source()` name disks of `config('filesystems.disks')`. There is no `sources` key in 2.0: a 1.x source with a local path becomes a disk when a project upgrades (`mediatheque:upgrade --config`, in the beta).
- The resolver passes the file's `source()` as the `ImageSource` disk, and its `path()` as its path.

### The version is the content's hash

- The version of a media source is the **first 8 hexadecimal characters of the resolved file's SHA-256 `checksum`**. It changes exactly when the content changes, needs no column, and survives copying a database.
- Every stored file has a checksum. Ingest computes the original's (for an original ingested from a disk, the probe job computes it while it reads the file). The `ffmpeg` driver computes the checksum of each file it writes. When a driver's output has none, the recorder computes it from the stored file.
- A file without a checksum yet (an original from a disk, before its probe) doesn't resolve.

### Keys name the media's image, or one of its files

| Key | Resolves to |
|---|---|
| `med_…` | The media's image: the original of an `image` media; `thumbnail()` for other types (the poster first) |
| `med_…/{handle}` | `file($handle)`: a single file, or the first file of a group (`med_…/poster`) |
| `med_…/{handle}/{index}` | `file($handle, $index)` (`med_…/thumbnails/2`) |

- The version is always the resolved file's.
- A key resolves only to a stored file whose mime is an image (`image/*`). Anything else doesn't resolve: a media without an image file, a handle it doesn't have, a video's original.

### What resolves, and when

- A **soft-deleted** media doesn't resolve.
- A **`pending` or `processing`** media resolves to its files that exist: an image's original exists from ingest, so it resolves before its probe ends (once its checksum is known), and `laravel-image` reads missing dimensions from the file. A video whose poster isn't produced yet doesn't resolve.
- A **`failed`** media is treated like a `pending` one: what was stored resolves.
- `isPrivate()` follows the media's visibility.
- Metadata passed to the `ImageSource`: the file's `width`, `height` and `mime`, and its `thumbhash` placeholder when it has one. `orientation`, the focal point and `transform` are added once [laravel-image#88](https://github.com/folkloreinc/laravel-image/issues/88) settles them.

### Reference forms

- `supports()` accepts the `Media` contract (never a concrete model), a `MediaReference`, a `media://med_…` string, a uid, and a key with a handle (`med_…/poster`), so code can name a file the way URLs do. `medias://{id}` is added with the compatibility layer, in the beta.
- `supports()` checks the form only (`Uid::isValid()` with the configured prefix) and never queries the database.
- `resolveKey()` accepts the key forms above; anything else, and keys that don't resolve, throw `laravel-image`'s `SourceNotFound`.

### The placeholder is a ThumbHash

- The canonical key **`thumbhash`** replaces `blurhash`: a [ThumbHash](https://evanw.github.io/thumbhash/) in base64, the format `laravel-image` uses for its placeholders. `mediatheque:upgrade` doesn't convert 1.x values; images get a ThumbHash when they are analyzed again.
- The local image prober adds it from `laravel-image`'s `Image::analyze()` when `laravel-image` is installed (alpha 2, with images at ingest). Without `laravel-image`, the key is missing.

### Registration and tests

- The resolver is registered under the name `media` when `laravel-image` 2 is installed, and not otherwise. `laravel-image` stays a suggested dependency.
- Its test case uses `laravel-image`'s `SourceResolverContractTests`, so it is held to the same rules as `laravel-image`'s own resolvers.

## Consequences

- Replacing a media's original, or producing a video's poster again, gives new image URLs, so caches never serve the old image.
- The resolver depends on a tagged `laravel-image` pre-release that ships `SourceResolver` and its contract tests. It ships in the first mediatheque alpha after that pre-release, whichever it is.
- Files written by remote drivers that don't report a checksum cost one read each, by the recorder. Eclair's output files would avoid it by reporting one.
- `docs/architecture.md` lists `thumbhash` instead of `blurhash` in the canonical keys.
