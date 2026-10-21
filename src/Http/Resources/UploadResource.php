<?php

namespace Folklore\Mediatheque\Http\Resources;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of an upload (docs/decisions/0017): how it stands or how
 * it ended, and the media it created. Its disk, path and multipart upload
 * id stay on the server; its creator is the application's to add. Eager
 * load `media` to list uploads in one query.
 *
 * @property Upload $resource
 */
class UploadResource extends JsonResource
{
    use FormatsTimes;

    public function __construct(Upload $upload)
    {
        parent::__construct($upload);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $upload = $this->resource;
        $media = match (true) {
            $upload->media_id === null => null,
            $upload->relationLoaded('media') => $upload->getRelation('media'),
            default => $upload->media()->withoutGlobalScopes()->first(),
        };

        return [
            'id' => $upload->uid,
            'status' => $upload->status->value,
            'reason' => $upload->reason,
            'filename' => $upload->filename,
            'size' => $upload->size,
            'media' => $media instanceof Media ? $media->uid() : null,
            'created_at' => self::time($upload->created_at),
            'expires_at' => self::time($upload->expires_at),
            'ended_at' => self::time($upload->ended_at),
        ];
    }
}
