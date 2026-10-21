<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Exceptions\FileRejected;
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
     * pipeline in progress. A probe is retried with probeAgain().
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
