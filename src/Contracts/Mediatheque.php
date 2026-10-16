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
     *
     * @throws FileRejected
     */
    public function ingest(
        mixed $source,
        ?Visibility $visibility = null,
        ?string $pipeline = null,
        ?string $name = null,
        array $data = [],
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
     */
    public function runPipeline(Media $media, ?string $pipeline = null, ?array $renditions = null, bool $force = false): ?PipelineRun;
}
