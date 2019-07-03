<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Support\HasMetadatas as HasMetadatasInterface;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Contracts\Support\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Contracts\Support\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Contracts\Support\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasMetadatas;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;

class Media extends Model implements
    MediaContract,
    HasFilesInterface,
    HasMetadatasInterface,
    HasUrlInterface,
    HasPipelinesInterface,
    HasThumbnailsInterface
{
    use HasFiles, HasUrl, HasPipelines, HasMetadatas, HasThumbnails;

    protected $table = 'medias';

    protected $fillable = [
        'type',
        'name',
    ];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        $observer = config('mediatheque.observers.media', null);
        if (!is_null($observer)) {
            static::observe($observer);
        }
    }

    /**
     * Get the type column name
     * @return string $name The type column name
     */
    public function getTypeName()
    {
        return 'type';
    }

    /**
     * Get the current media type
     * @return string $type The type of the media
     */
    public function getType()
    {
        return $this->getAttribute($this->getTypeName());
    }

    /**
     * Set the current media type
     * @param string $type The type of the media
     */
    public function setType($type)
    {
        return $this->setAttribute($this->getTypeName(), $type);
    }

    /**
     * Set the type atribute
     * @param string|TypeContract $value The type
     */
    protected function setTypeAttribute($value)
    {
        $this->attributes['type'] = $value instanceof TypeContract ? $value->getName() : $value;
    }

    /**
     * Scope a query to only include specific type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array|string  $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeType($query, $type)
    {
        return is_array($type) ? $query->whereIn('type', $type) : $query->where('type', $type);
    }
}
