<?php

namespace Folklore\Mediatheque;

use Closure;
use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Monitoring\Monitoring;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Pipelines\RunActions;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
        array $attributes = [],
    ): Media {
        return $this->container->make(Ingestor::class)->ingest(Source::from($source), new IngestOptions(
            visibility: $visibility,
            pipeline: $pipeline,
            name: $name,
            data: $data,
            attributes: $attributes,
        ));
    }

    /**
     * Narrow what monitoring reads (docs/decisions/0017): the closure
     * receives a query of media, with the kind `media`, without the
     * application's global scopes. Runs and renditions are narrowed
     * through their media.
     *
     * @param  (Closure(Builder<covariant Model>, string): mixed)|null  $scope
     */
    public function monitoringScope(?Closure $scope): void
    {
        $this->container->make(Monitoring::class)->scopeUsing($scope);
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
        return $this->container->make(PipelineRunner::class)->run($media, $pipeline, $renditions, $force);
    }

    public function retry(PipelineRun $run): ?PipelineRun
    {
        return $this->container->make(RunActions::class)->retry($run);
    }

    public function cancel(PipelineRun $run): bool
    {
        return $this->container->make(RunActions::class)->cancel($run);
    }

    public function refresh(PipelineRun $run): bool
    {
        return $this->container->make(RunActions::class)->refresh($run);
    }

    public function probeAgain(Media $media): bool
    {
        return $this->container->make(RunActions::class)->probeAgain($media);
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
