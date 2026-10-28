<?php

namespace Folklore\Mediatheque\Http\Resources;

use Carbon\CarbonInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The canonical JSON shape of a media (docs/decisions/0010). It has the
 * meaning of Panneau 5's `MediaInfo` in snake_case, so an adapter only
 * renames its keys, plus what an application needs:
 *
 * - `id` is the uid. `mime` and `size` are the original's; `width`,
 *   `height` and `duration` come from the metadata.
 * - `url` is the file the media plays (`$media->url()`); `thumbnail_url` is
 *   that file for an image, and `thumbnail()`'s otherwise (the poster
 *   first). An application that wants a `laravel-image` preset overrides
 *   thumbnailUrl().
 * - `error` is `failure()` while the media is `failed`, and `processing`
 *   its run in progress while it is `pending` or `processing`: each costs a
 *   query, for those media only.
 * - `metadata` is the media's, without null values. `files` is an object
 *   keyed by handle, with a list for a group (FileResource).
 * - Times are ISO 8601 in UTC, for a media that has them (an Eloquent
 *   model with timestamps).
 *
 * Applications extend it to add their fields.
 *
 * @property Media $resource
 */
class MediaResource extends JsonResource
{
    use FormatsTimes;

    public function __construct(Media $media)
    {
        parent::__construct($media);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;
        $status = $media->status();
        $original = $media->original();
        $processing = $status === MediaStatus::Pending || $status === MediaStatus::Processing ? $media->processing() : null;

        return [
            'id' => $media->uid(),
            'type' => $media->type(),
            'name' => $media->name(),
            'status' => $status->value,
            'visibility' => $media->visibility()->value,
            'error' => $status === MediaStatus::Failed ? $media->failure()?->toArray() : null,
            'processing' => $processing !== null ? [
                'pipeline' => $processing->pipeline,
                'progress' => $processing->progress,
                'rendition' => $processing->rendition,
            ] : null,
            'mime' => $original?->mime() ?? self::string($media->metadata(MetadataKey::Mime->value)),
            'size' => $original?->size() ?? self::int($media->metadata(MetadataKey::Size->value)),
            'width' => self::int($media->metadata(MetadataKey::Width->value)),
            'height' => self::int($media->metadata(MetadataKey::Height->value)),
            'duration' => self::number($media->metadata(MetadataKey::Duration->value)),
            'url' => FileResource::url(fn () => $media->url()),
            'thumbnail_url' => $this->thumbnailUrl($media),
            'transform' => $media->transform(),
            'metadata' => (object) array_filter($media->metadata(), fn (mixed $value) => $value !== null),
            'files' => (object) $this->files($media, $request),
            'created_at' => self::time(self::timestamp($media, 'created')),
            'updated_at' => self::time(self::timestamp($media, 'updated')),
        ];
    }

    /**
     * The URL of the media's thumbnail: the file an image plays, or the
     * poster or first thumbnail of other types.
     */
    protected function thumbnailUrl(Media $media): ?string
    {
        if ($media->type() === 'image') {
            return FileResource::url(fn () => $media->url());
        }

        $thumbnail = $media->thumbnail();

        return $thumbnail !== null ? FileResource::url(fn () => $thumbnail->url()) : null;
    }

    /**
     * The files by handle, the original first; a group is a list, ordered
     * by index.
     *
     * @return array<string, array<string, mixed>|list<array<string, mixed>>>
     */
    protected function files(Media $media, Request $request): array
    {
        $files = $media->original() !== null ? [File::ORIGINAL => []] : [];

        foreach ($media->files() as $file) {
            $rendered = (new FileResource($file))->toArray($request);

            if ($file->index() === null) {
                $files[$file->handle()] = $rendered;
            } else {
                $group = $files[$file->handle()] ?? [];
                $group[] = $rendered;
                $files[$file->handle()] = $group;
            }
        }

        return $files;
    }

    private static function timestamp(Media $media, string $which): ?CarbonInterface
    {
        if (! $media instanceof Model || ! $media->usesTimestamps()) {
            return null;
        }

        $column = $which === 'created' ? $media->getCreatedAtColumn() : $media->getUpdatedAtColumn();
        $value = $column !== null ? $media->getAttribute($column) : null;

        return $value instanceof CarbonInterface ? $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }
}
