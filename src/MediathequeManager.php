<?php

namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use LogicException;

/**
 * The package's entry point, behind the `Mediatheque` facade, on the
 * application's Eloquent models.
 */
class MediathequeManager implements Mediatheque
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function ingest(
        mixed $source,
        ?Visibility $visibility = null,
        ?string $pipeline = null,
        ?string $name = null,
        array $data = [],
    ): Media {
        return $this->container->make(Ingestor::class)->ingest(Source::from($source), new IngestOptions(
            visibility: $visibility,
            pipeline: $pipeline,
            name: $name,
            data: $data,
        ));
    }

    public function resolve(MediaReference|string $reference): ?Media
    {
        $reference = self::reference($reference);

        if ($reference === null) {
            return null;
        }

        $query = Models::media()::query()->with('mediaFiles');

        return $reference->isLegacy()
            ? $query->whereKey($reference->id)->first()
            : $query->where('uid', $reference->uid)->first();
    }

    public function resolveMany(iterable $references): Collection
    {
        $parsed = [];
        foreach ($references as $reference) {
            $parsed[(string) $reference] = self::reference($reference);
        }

        $uids = array_values(array_filter(array_map(fn (?MediaReference $reference) => $reference?->uid, $parsed)));
        $ids = array_values(array_filter(array_map(fn (?MediaReference $reference) => $reference?->id, $parsed)));

        $byUid = [];
        foreach ($uids === [] ? [] : Models::media()::query()->with('mediaFiles')->whereIn('uid', $uids)->get() as $media) {
            $byUid[$media->uid()] = $media;
        }

        $byId = [];
        foreach ($ids === [] ? [] : Models::media()::query()->with('mediaFiles')->whereKey($ids)->get() as $media) {
            $key = $media->getKey();
            if (is_int($key) || is_string($key)) {
                $byId[(int) $key] = $media;
            }
        }

        $resolved = [];
        foreach ($parsed as $key => $reference) {
            $media = match (true) {
                $reference === null => null,
                $reference->isLegacy() => $byId[$reference->id] ?? null,
                default => $byUid[$reference->uid] ?? null,
            };

            if ($media !== null) {
                $resolved[$key] = $media;
            }
        }

        return self::collect($resolved);
    }

    public function runPipeline(Media $media, ?string $pipeline = null, ?array $renditions = null, bool $force = false): ?PipelineRun
    {
        throw new LogicException('Pipelines run with the ffmpeg transcoder, which is not available yet.');
    }

    /**
     * A reference from what applications pass: a reference, `media://…`,
     * `medias://…` or a uid. Null for anything else.
     */
    private static function reference(MediaReference|string $reference): ?MediaReference
    {
        if ($reference instanceof MediaReference) {
            return $reference;
        }

        return Uid::isValid($reference) ? MediaReference::uid($reference) : MediaReference::tryParse($reference);
    }

    /**
     * @param  array<string, Media>  $media
     * @return Collection<string, Media>
     */
    private static function collect(array $media): Collection
    {
        return new Collection($media);
    }
}
