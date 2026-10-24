<?php

namespace Folklore\Mediatheque\Concerns;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Support\Models;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Media attached to an application model by handle, on the `medias_pivot`
 * table (docs/decisions/0020). The pivot is also the usage graph: a media
 * attached to a model is in use, and can't be deleted unless the deletion is
 * forced.
 *
 * Writes go by handle: attachMedia() adds to a handle, and syncMedias()
 * replaces the media of the handles it names only, so code that owns a
 * handle (a form, the sync of a JSON column) never detaches the media that
 * other code attached under another one.
 *
 * @phpstan-require-extends Model
 */
trait HasMedias
{
    /**
     * A deleted model no longer uses its media. A soft-deleted one keeps
     * them, and uses them again when it is restored.
     */
    public static function bootHasMedias(): void
    {
        static::deleted(function (self $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->mediaPivotRows()->delete();
        });
    }

    /**
     * Without a handle, the relation to every media attached to the model,
     * with `handle` and `order` on its pivot. With a handle, the media
     * attached under it, in their order, read from the loaded relation.
     *
     * @return ($handle is null ? MorphToMany<Model&Media, $this> : Collection<int, Model&Media>)
     */
    public function medias(?string $handle = null): MorphToMany|Collection
    {
        if ($handle !== null) {
            return $this->loadedMedias()
                ->filter(fn (Model $media) => self::pivotOf($media)?->getAttribute('handle') === $handle)
                ->values()
                ->toBase();
        }

        return $this->morphToMany(Models::media(), 'morphable', Models::table('medias_pivot'), 'morphable_id', 'media_id')
            ->withPivot('handle', 'order')
            ->withTimestamps()
            ->orderByPivot('order')
            ->orderByPivot('id');
    }

    /**
     * The first media attached under a handle.
     */
    public function media(string $handle): (Model&Media)|null
    {
        return $this->medias($handle)->first();
    }

    /**
     * Attach a media under a handle, after the media already there. A media
     * already attached under this handle stays where it is.
     *
     * @param  Media|int  $media  A media, or its key
     *
     * @throws ModelNotFoundException When no media has this key
     */
    public function attachMedia(Media|int $media, string $handle): void
    {
        $this->ensureMediasCanBeAttached();
        $key = self::mediaKey($media);

        if ($this->existingMediaKeys([$key]) === []) {
            throw (new ModelNotFoundException)->setModel(Models::media(), [$key]);
        }

        $this->getConnection()->transaction(function () use ($key, $handle) {
            $rows = $this->mediaPivotRows()->where('handle', $handle);

            if ((clone $rows)->where('media_id', $key)->exists()) {
                return;
            }

            $last = (clone $rows)->max('order');
            $this->medias()->attach($key, ['handle' => $handle, 'order' => is_numeric($last) ? (int) $last + 1 : 0]);
        });

        $this->unsetRelation('medias');
    }

    /**
     * Replace the media of each handle given, in the order given; the other
     * handles are left as they are. An empty list or null detaches every
     * media of its handle. Keys that no media has are ignored, as a stale
     * reference in JSON data would be.
     *
     * ```php
     * $post->syncMedias(['cover' => $cover, 'gallery' => [$a, $b]]);
     * $page->syncMedias(['json' => $ids]);
     * ```
     *
     * @param  array<string, Media|int|iterable<Media|int>|null>  $medias  Media, or their keys, by handle
     */
    public function syncMedias(array $medias): void
    {
        $this->ensureMediasCanBeAttached();

        $keysByHandle = [];
        foreach ($medias as $handle => $value) {
            $keysByHandle[(string) $handle] = self::mediaKeys($value);
        }

        $existing = array_flip($this->existingMediaKeys(array_merge([], ...array_values($keysByHandle))));

        $this->getConnection()->transaction(function () use ($keysByHandle, $existing) {
            foreach ($keysByHandle as $handle => $keys) {
                $this->syncMediasOfHandle($handle, array_values(array_filter($keys, fn (int $key) => isset($existing[$key]))));
            }
        });

        $this->unsetRelation('medias');
    }

    /**
     * Detach a media, from one handle or from all of them.
     *
     * @return int The number of attachments removed
     */
    public function detachMedia(Media|int $media, ?string $handle = null): int
    {
        $rows = $this->mediaPivotRows()->where('media_id', self::mediaKey($media));

        if ($handle !== null) {
            $rows->where('handle', $handle);
        }

        $detached = $rows->delete();
        $this->unsetRelation('medias');

        return $detached;
    }

    /**
     * @param  list<int>  $keys  Unique, in their order
     */
    private function syncMediasOfHandle(string $handle, array $keys): void
    {
        $positions = array_flip($keys);
        $kept = [];
        $obsolete = [];
        $now = $this->freshTimestampString();

        $rows = $this->mediaPivotRows()
            ->where('handle', $handle)
            ->orderBy('order')
            ->orderBy('id')
            ->get(['id', 'media_id', 'order']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, media_id: int|string, order: int|string} $row */
            $key = (int) $row->media_id;

            // A row of a media no longer listed, or a duplicate a 1.x table may hold
            if (! isset($positions[$key]) || isset($kept[$key])) {
                $obsolete[] = $row->id;

                continue;
            }

            $kept[$key] = true;

            if ((int) $row->order !== $positions[$key]) {
                $this->mediaPivotRows()->where('id', $row->id)->update(['order' => $positions[$key], 'updated_at' => $now]);
            }
        }

        if ($obsolete !== []) {
            $this->mediaPivotRows()->whereIn('id', $obsolete)->delete();
        }

        foreach ($keys as $position => $key) {
            if (! isset($kept[$key])) {
                $this->medias()->attach($key, ['handle' => $handle, 'order' => $position]);
            }
        }
    }

    /**
     * The rows of `medias_pivot` that attach media to this model.
     */
    protected function mediaPivotRows(): Builder
    {
        return $this->medias()
            ->newPivotStatement()
            ->where('morphable_type', $this->getMorphClass())
            ->where('morphable_id', $this->getKey());
    }

    /**
     * @return EloquentCollection<int, Model&Media>
     */
    private function loadedMedias(): EloquentCollection
    {
        if (! $this->relationLoaded('medias')) {
            $this->setRelation('medias', $this->medias()->get());
        }

        /** @var EloquentCollection<int, Model&Media> $medias */
        $medias = $this->getRelation('medias');

        return $medias;
    }

    /**
     * Of the given keys, those that a media has, trashed or not, whatever
     * the application's global scopes.
     *
     * @param  list<int>  $keys
     * @return list<int>
     */
    private function existingMediaKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $class = Models::media();
        $media = new $class;

        return array_values(array_map(
            fn (mixed $key) => is_numeric($key) ? (int) $key : 0,
            $class::query()->withoutGlobalScopes()->whereKey($keys)->pluck($media->getKeyName())->all(),
        ));
    }

    private function ensureMediasCanBeAttached(): void
    {
        if (! $this->exists) {
            throw new LogicException(sprintf('Save the %s before attaching media to it.', static::class));
        }
    }

    /**
     * @return list<int>
     */
    private static function mediaKeys(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_iterable($value)) {
            $value = [$value];
        }

        $keys = [];
        foreach ($value as $media) {
            if (! $media instanceof Media && ! is_int($media)) {
                throw new InvalidArgumentException(sprintf('Expected a media or its key, got %s.', get_debug_type($media)));
            }

            $keys[] = self::mediaKey($media);
        }

        return array_values(array_unique($keys));
    }

    private static function mediaKey(Media|int $media): int
    {
        if (is_int($media)) {
            return $media;
        }

        $key = $media instanceof Model ? $media->getKey() : null;

        if (! is_int($key)) {
            throw new InvalidArgumentException(sprintf(
                'HasMedias attaches media stored in the database; %s has no key.',
                $media->uid(),
            ));
        }

        return $key;
    }

    private static function pivotOf(Model $media): ?Model
    {
        $pivot = $media->relationLoaded('pivot') ? $media->getRelation('pivot') : null;

        return $pivot instanceof Model ? $pivot : null;
    }
}
