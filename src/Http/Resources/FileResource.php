<?php

namespace Folklore\Mediatheque\Http\Resources;

use Folklore\Mediatheque\Contracts\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RuntimeException;

/**
 * The JSON shape of a file of a media (docs/decisions/0010): its handle, its
 * `index` in a group, role, name, URL, mime and size, then its `width`,
 * `height`, `duration` and `bitrate` when known, and `parts_count` for a
 * file of several parts (HLS). No internal id.
 *
 * @property File $resource
 */
class FileResource extends JsonResource
{
    public function __construct(File $file)
    {
        parent::__construct($file);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $file = $this->resource;

        return [
            'handle' => $file->handle(),
            ...($file->index() !== null ? ['index' => $file->index()] : []),
            'role' => $file->role(),
            'name' => $file->name(),
            'url' => self::url(fn () => $file->url()),
            'mime' => $file->mime(),
            'size' => $file->size(),
            ...array_filter([
                'width' => $file->width(),
                'height' => $file->height(),
                'duration' => $file->duration(),
                'bitrate' => $file->bitrate(),
                'parts_count' => $file->partsCount(),
            ], fn (int|float|null $value) => $value !== null),
        ];
    }

    /**
     * A URL, or null when the disk gives none (a private file on a local
     * disk without `serve`).
     *
     * @param  callable(): ?string  $url
     */
    public static function url(callable $url): ?string
    {
        try {
            return $url();
        } catch (RuntimeException) {
            return null;
        }
    }
}
