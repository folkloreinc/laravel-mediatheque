<?php

namespace Folklore\Mediatheque\Tests\Concerns;

use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;

/**
 * Rows of the package's Eloquent models, for tests that don't go through
 * ingest.
 */
trait CreatesMedia
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createMedia(array $attributes = []): Media
    {
        $media = new Media;
        $media->forceFill([
            'type' => 'video',
            'name' => 'Clip',
            'status' => 'ready',
            'visibility' => 'public',
            ...$attributes,
        ])->save();

        return $media;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createFile(Media $media, string $handle = 'original', array $attributes = []): File
    {
        $file = new File;
        $file->forceFill([
            'media_id' => $media->id,
            'handle' => $handle,
            'role' => $handle === 'original' ? 'original' : 'main',
            'source' => 'public',
            'path' => $media->uid.'/'.$handle.(isset($attributes['index']) && is_int($attributes['index']) ? '-'.$attributes['index'] : '').'.bin',
            'mime' => 'application/octet-stream',
            'size' => 3,
            ...$attributes,
        ])->save();

        $media->unsetRelation('mediaFiles');

        return $file;
    }
}
