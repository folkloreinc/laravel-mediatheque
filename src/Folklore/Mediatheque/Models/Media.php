<?php

namespace Folklore\Mediatheque\Models;

use Illuminate\Database\Eloquent\Builder;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasMetadatas;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;

class Media extends Model implements MediaContract
{
    use HasFiles, HasUrl, HasPipelines, HasMetadatas, HasThumbnails;

    protected $table = 'medias';

    protected $fillable = ['type', 'name'];

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
    public function getTypeName(): string
    {
        return 'type';
    }

    /**
     * Get the current media type
     * @return string $type The type of the media
     */
    public function getType(): TypeContract
    {
        $typeName = $this->getAttribute($this->getTypeName());
        return resolve(TypeFactory::class)->type($typeName);
    }

    /**
     * Set the current media type
     * @param string $type The type of the media
     */
    public function setType($type): void
    {
        $this->setAttribute(
            $this->getTypeName(),
            $type instanceof TypeContract ? $type->getName() : $type
        );
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
        if ($value instanceof TypeContract) {
            return $query->where($this->getTypeName(), $type->name());
        }
        return is_array($type)
            ? $query->whereIn($this->getTypeName(), $type)
            : $query->where($this->getTypeName(), $type);
    }
}
