# 0025: Document thumbnails with Poppler

- Status: proposed
- Date: 2026-10-29

## Context

1.x made an image of every page of each document (its `Document\Thumbnails` job, ImageMagick through Imagick, every page at 150 dpi in JPEG at quality 100 on white). Sites show them: a media kit's preview, a document's first page in a list. 2.0 had no operation for documents, so `mediatheque:upgrade --config` dropped the job, and new documents of an upgraded site got no thumbnail while its older ones kept theirs ([#147](https://github.com/folkloreinc/laravel-mediatheque/issues/147)). The roadmap lists "Documents: page thumbnails" under parity with 1.x.

Eclair already has the operation, `document.thumbnails`, with the same defaults, rendered by Poppler's `pdftoppm`: never by ImageMagick and Ghostscript, which read PostScript and whose security policy in most distributions refuses PDFs.

## Decision

- **The `ffmpeg` driver runs `document.thumbnails`**, in Eclair's vocabulary ([0011](0011-renditions-on-remote-transcoders.md)) and with its defaults: every page, or the first `count`, at `resolution` dots per inch (150), in `format` `jpeg` (the default) or `png`, at `quality` (100), on white, 1000 pages at most. The pages are a group with indexes from 0, role `thumbnail`. `pages`, `maxWidth` and `maxHeight` of Eclair's operation come when a project needs them.
- **It runs `pdftoppm`**, from `binaries.pdftoppm` (`MEDIATHEQUE_PDFTOPPM`, `pdftoppm` in the `PATH` by default), without a shell, never ImageMagick.
- **The package has a default `document` pipeline** with this one rendition, `thumbnails`, as 1.x and Eclair's `mediatheque/document` preset have. It has no main rendition, so a document is `ready` once probed ([0014](0014-run-outcomes-and-model-contracts.md)) and its thumbnails follow.
- **Poppler is optional.** A server without `pdftoppm` fails the rendition with `operation_failed` and a message that says what to install, instead of throwing so the job is retried as a missing ffmpeg does: a document doesn't need its thumbnails to be ready, and a sync queue would otherwise fail the upload. The run is `failed`, its document `ready`; `Mediatheque::runPipeline()` produces the thumbnails once Poppler is installed.
- 1.x's `Document\Thumbnails` job becomes `document.thumbnails` with its `count`, `resolution`, `format` and `quality`, so `--config` keeps a project's document pipeline, and a 1.x config published whole gets the 2.0 default.

## Consequences

- A server that runs the `ffmpeg` driver installs `poppler-utils` for document thumbnails; CI installs it.
- A long document makes as many files as it has pages, as in 1.x. A project that only shows the first page sets `count` to 1.
- Options of 1.x's job that `pdftoppm` doesn't have (`background`, `font`, `flatten`, `trim`) are dropped: pages are rendered on white.
- The page count of documents (1.x's `pages_count`) is still to come: the probe doesn't read it yet.
