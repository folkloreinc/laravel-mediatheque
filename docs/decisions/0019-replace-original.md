# 0019: Replacing a media's original

- Status: accepted
- Date: 2026-10-22

## Context

[The architecture](../architecture.md#deletion-and-replacement) plans `$media->replaceOriginal($source)`: it keeps the `uid`, stores the new original, deletes the renditions in a queued job and runs the pipeline again. Two consumers need it ([#87](https://github.com/folkloreinc/laravel-mediatheque/issues/87)). Panneau 5 replaces a media's file with a `PATCH` of its library item. The pilot project fixes a wrong image, or puts in a new cut of a video, without breaking the content that refers to the media (`media://med_…`).

The architecture left these questions open:

- what happens when the new file is of another type (an image replaced by a video);
- which of the media's attributes are kept, and what happens to its runs in progress;
- what `OriginalReplaced` carries;
- how a replacement stays safe to retry, as every operation of the package must ([0012](0012-pipeline-runs-recorder-and-probe.md)), when a file on a disk is not read in the request ([0015](0015-new-install-schema-attributes-and-uploads.md)).

## Decision

### API

- `Mediatheque::replaceOriginal(Media $media, $source, ?IngestOptions $options = null, bool $allowTypeChange = false): Media` is on the `Mediatheque` contract, the manager and `Mediatheque::fake()`. The package's `Media` model and the fake's media have the shortcut `$media->replaceOriginal($source, $options, $allowTypeChange)`. It is not on the `Media` contract, which stays read-only.
- `$source` takes what `ingest()` takes: an uploaded file, a path, a stream or a `Source`. `Source::disk()` is how Panneau replaces a file that the browser uploaded directly to storage.
- The file goes through the same steps as ingest. Its type comes from its content. Its size and mime are checked, and SVG files are sanitized. A file that ingest would refuse is refused with `FileRejected` and `IngestRejected`, and nothing changes.

### The type can't change, unless the caller allows it

- A file of another type than the media's is refused with `RejectionReason::TypeChanged`. A reference to a media keeps naming the same kind of content: an image in an article stays an image.
- `allowTypeChange: true` allows it. The media takes the new type. Its pipeline is the new type's, unless `$options->pipeline` names one.
- It is a parameter of `replaceOriginal()`, not of `IngestOptions`: ingest has no previous type to compare.

### What is kept, and what is new

- Kept: the `uid` and the internal id, the visibility, the application's data and attributes (a tenant id), the usages, and the name unless `$options->name` gives one. Of the options, only `name`, `pipeline` and `source` are read. The source is the current original's disk by default, not the config's.
- New: the original's file and row, with its mime, size and checksum, and the media's metadata, which is rebuilt from the new file (`filename`, `mime`, `extension`, `size`, `checksum`) until its probe. Metadata outputs of the previous renditions (palette, ThumbHash) go with them.
- The media goes back to `pending`. Its probe is queued, then its pipeline runs: `$options->pipeline`, else the one ingest chose while the type is the same, else the type's.

### Files

- The new original is stored at a path of its own: the original's path, with a version added to its file name (`original-{version}.mp4`). The version is the first 16 hexadecimal characters of the file's checksum. For a file copied from a disk, which is not read, it comes from the file's disk, path, size and modification date. A replacement never writes over the file it replaces.
- The renditions' rows are removed when the original is replaced, so `url()` serves the new original until the new main rendition exists, never an old rendition of the previous content. The previous files are deleted in a queued job (`DeleteStoredFiles`) once the new original is committed. If storing or saving fails, the new file is deleted and the media is unchanged.
- `DeleteStoredFiles` keeps a path that a file records again by the time it runs. A file that a queued deletion would remove can't be lost this way: when an original is replaced, then put back.

### Runs in progress

- The runs of the media that haven't ended, its probe included, are canceled in the same transaction. Their renditions get the reason "The media's original was replaced." The media's status isn't changed by it: it is `pending`, not `failed`, and `MediaFailed` is not dispatched. `RenditionCanceled` and `PipelineCanceled` are dispatched after the commit.
- Then each canceled run's transcoder is asked to stop its job, when it has the `cancel` capability. Its renditions are canceled already, so a driver stops its job without reporting anything new. A failure to stop it is reported, and the replacement stands.
- Late results are ignored by the recorder's rules: a canceled rendition is final, and statuses only move forward ([0012](0012-pipeline-runs-recorder-and-probe.md)).

### `OriginalReplaced`

- `OriginalReplaced(Media $media, ?string $previousChecksum, ?string $checksum)` is dispatched after the commit, before the probe is queued. `laravel-image` clears the derivatives of the previous version on it.
- A checksum is null for an original copied from a disk until its probe computes it ([0013](0013-media-resolver-for-laravel-image.md)). The new checksum is then on the original once it is probed.
- The version of `laravel-image` URLs is the checksum's ([0013](0013-media-resolver-for-laravel-image.md)), so replacing an image gives new URLs. An original copied from a disk doesn't resolve until its probe has computed its checksum, as after ingest.

### Safe to retry

- A file that is the original already changes nothing: the same checksum, or for a file copied from a disk, the same path. Nothing is stored, canceled or run again, and `OriginalReplaced` is not dispatched. Only a name given in the options is applied.
- Replacements of the same media run one after the other, under a lock on the media's row. A second replacement with the same file finds the original already replaced, and deletes nothing that the first one stored.
- Temporary files are removed, as at ingest.

## Consequences

- laravel-panneau can accept a new `file` when a `MediathequeResource` item is updated, and lift the field's `createOnly()`.
- `OriginalReplaced` carries checksums instead of the previous `File`, whose row is removed. It was never dispatched before this decision.
- The `Mediatheque` contract has a new method: a class that implements it implements `replaceOriginal()` too.
- A replaced original from a disk is not read in the request, like an ingested one. The cost is that the same file uploaded again to another path is a new file: its path's version differs, and it is replaced and probed again.
- A probe job that is reading the previous original when it is replaced finishes reading it. It records nothing, since its run is canceled, and the new original's probe is queued.
