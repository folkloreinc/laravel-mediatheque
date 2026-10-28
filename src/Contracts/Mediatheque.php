<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\References\MediaReference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * The package's entry point, behind the `Mediatheque` facade.
 */
interface Mediatheque
{
    /**
     * Ingest a file: an uploaded file, a local path, a stream, or a Source
     * (`Source::disk()`, `Source::url()`). See Ingestor.
     *
     * @param  UploadedFile|Source|string|resource  $source
     * @param  array<string, mixed>  $data  The media's application data
     * @param  array<string, mixed>  $attributes  Columns of the application's media model, such as a tenant id, set before the path is built
     *
     * @throws FileRejected
     */
    public function ingest(
        mixed $source,
        ?Visibility $visibility = null,
        ?string $pipeline = null,
        ?string $name = null,
        array $data = [],
        array $attributes = [],
    ): Media;

    /**
     * Replace a media's original with another file (docs/decisions/0019).
     * `$source` takes what ingest() takes, and the file is validated by the
     * same rules: a file ingest would refuse throws FileRejected, and
     * nothing changes.
     *
     * - The media keeps its uid, visibility, application data and
     *   attributes, its usages, and its name unless `$options` names it. Of
     *   the options, only `name`, `pipeline` and `source` are read: the
     *   disk is the current original's by default.
     * - Its original, with its mime, size and checksum, and its metadata are
     *   the new file's. It goes back to `pending`: it is probed again, then
     *   its pipeline runs, `$options->pipeline` or the one it ran before.
     * - Its renditions are removed, and their files deleted in a queued job
     *   once the new original is stored. Its runs that haven't ended are
     *   canceled, through their transcoder when it can, and their late
     *   results are ignored.
     * - A file of another type is refused (`RejectionReason::TypeChanged`),
     *   so references keep a media of the same kind, unless
     *   `$allowTypeChange` is set.
     * - `OriginalReplaced` is dispatched, with the previous and the new
     *   checksum.
     * - Safe to retry: replacing an original with the same file stores
     *   nothing, cancels nothing and runs nothing again. Only a name given
     *   in `$options` is applied.
     *
     * @param  UploadedFile|Source|string|resource  $source
     *
     * @throws FileRejected
     */
    public function replaceOriginal(Media $media, mixed $source, ?IngestOptions $options = null, bool $allowTypeChange = false): Media;

    /**
     * Change a media's visibility (docs/decisions/0007). False when it has
     * this visibility already: nothing changes, and nothing is dispatched.
     *
     * - The media has its new visibility at once, so `url()` gives
     *   temporary URLs of a media made private before its files follow.
     * - Its stored files, the original and the renditions, follow in a
     *   queued job, which applies the media's visibility when it runs: a
     *   later change wins, and a retried job changes nothing.
     * - The files of a run in progress get it when the run records them.
     * - `MediaVisibilityChanged` is dispatched, with the previous and the
     *   new visibility.
     */
    public function setVisibility(Media $media, Visibility $visibility): bool;

    /**
     * A media by reference: `media://med_…`, `medias://123` (1.x), or a uid.
     * Null when it doesn't exist or is deleted.
     */
    public function resolve(MediaReference|string $reference): ?Media;

    /**
     * Many references at once, in one query per form. Keyed by the reference
     * as given; references that don't resolve are missing.
     *
     * @param  iterable<MediaReference|string>  $references
     * @return Collection<string, Media>
     */
    public function resolveMany(iterable $references): Collection;

    /**
     * Run a pipeline on a media: its type's pipeline by default. Only the
     * renditions that are missing, failed or changed are produced, unless
     * `$force` is set. Returns null when there is nothing to produce.
     *
     * @param  list<string>|null  $renditions  Only these renditions (and those they read from)
     *
     * @phpstan-impure
     */
    public function runPipeline(Media $media, ?string $pipeline = null, ?array $renditions = null, bool $force = false): ?PipelineRun;

    /**
     * Retry a run that ended: a new run of its failed and canceled
     * renditions, produced again even if a file exists for them
     * (docs/decisions/0012, 0017). Null when the run hasn't ended, has
     * nothing to retry, or when its media already has a run of the same
     * pipeline in progress. A rendition that a later run of the pipeline
     * completed is not retried: retrying twice creates one run. A probe is
     * retried with probeAgain().
     *
     * @phpstan-impure
     */
    public function retry(PipelineRun $run): ?PipelineRun;

    /**
     * Cancel a run that hasn't ended, through its transcoder's `cancel`
     * capability. False when the run has ended or can't be canceled.
     */
    public function cancel(PipelineRun $run): bool;

    /**
     * Ask the run's transcoder for its state, through its `refresh`
     * capability. False when the run has ended or can't be refreshed.
     */
    public function refresh(PipelineRun $run): bool;

    /**
     * Probe a media whose probe failed again: it is `pending` until the new
     * probe completes, then its pipeline runs, the one ingest chose. False
     * for a media that isn't `failed`, or that was probed.
     */
    public function probeAgain(Media $media): bool;
}
