# 0025: Document thumbnails with Poppler

- Status: proposed
- Date: 2026-10-29

## Context

1.x made an image of every page of each document (its `Document\Thumbnails` job, ImageMagick through Imagick, every page at 150 dpi in JPEG at quality 100 on white). Sites show them: a media kit's preview, a document's first page in a list. 2.0 had no operation for documents, so `mediatheque:upgrade --config` dropped the job, and new documents of an upgraded site got no thumbnail while its older ones kept theirs ([#147](https://github.com/folkloreinc/laravel-mediatheque/issues/147)). The roadmap lists "Documents: page thumbnails" under parity with 1.x.

Eclair already has the operation, `document.thumbnails`, with the same defaults, rendered by Poppler's `pdftoppm`: never by ImageMagick and Ghostscript, which read PostScript and whose security policy in most distributions refuses PDFs.

## Decision

- **The `ffmpeg` driver runs `document.thumbnails`**, in Eclair's vocabulary ([0011](0011-renditions-on-remote-transcoders.md)) and with its defaults: every page, or the first `count` (a number, or `all`), at `resolution` dots per inch (150), in `format` `jpeg` (the default) or `png`, at `quality` (100), on white, 1000 pages at most. The pages are a group with indexes from 0, role `thumbnail`. As the driver's video frames, a page is fitted in `max_width` and `max_height`, 1920 pixels by default, by lowering its resolution; a page pdftoppm can't allocate (it writes a single pixel) fails with `limit_exceeded`. `pages` of Eclair's operation comes when a project needs it.
- **It runs Poppler**, `pdfinfo` for the pages and their sizes, then `pdftoppm` for each page, from `binaries.pdfinfo` and `binaries.pdftoppm` (`MEDIATHEQUE_PDFINFO`, `MEDIATHEQUE_PDFTOPPM`, in the `PATH` by default), without a shell, never ImageMagick.
- **The package has a default `document` pipeline that renders the first page** of PDFs (`count` 1, `when.mime` `application/pdf`, amended on 2026-10-29 in review): sites use it as the document's thumbnail, and every page of a long document is up to 1000 large images in one job. 1.x rendered every page; `count: 'all'` keeps that. The pipeline has no main rendition, so a document is `ready` once probed ([0014](0014-run-outcomes-and-model-contracts.md)) and its thumbnail follows. Other files a document type accepts, as 1.x's `text/plain`, run nothing.
- **Poppler is optional.** A server without it fails the rendition with `operation_failed` and a message that says what to install, instead of throwing so the job is retried as a missing ffmpeg does: a document doesn't need its thumbnails to be ready, and a sync queue would otherwise fail the upload. The binaries are checked before the input is copied to the worker (`ChecksRequirements`), so a 200 MB PDF on S3 isn't downloaded for nothing. The run is `failed`, its document `ready`; `Mediatheque::runPipeline()` produces the thumbnails once Poppler is installed.
- 1.x's `Document\Thumbnails` job becomes `document.thumbnails` with its `count` (`all` by default, as in 1.x), `resolution`, `format` and `quality`, so `--config` keeps a project's own document pipeline with every page. A 1.x document pipeline equal to the 1.x default gets the 2.0 default, the first page: `--config` and `--check` say so. A 1.x `format` other than JPEG and PNG is dropped, with a notice.

## Consequences

- A server that runs the `ffmpeg` driver installs `poppler-utils` for document thumbnails; CI installs it.
- An upgraded site gets a thumbnail of the first page of new documents, and keeps the thumbnails of every page 1.x made. A site that shows other pages sets `count`.
- An Eclair pipeline reads `count: 'all'` as no `count`: Eclair's default is every page.
- Options of 1.x's job that Poppler doesn't have (`background`, `font`, `flatten`, `trim`) are dropped: pages are rendered on white.
- The page count of documents (1.x's `pages_count`) is still to come: the probe doesn't read it yet.
