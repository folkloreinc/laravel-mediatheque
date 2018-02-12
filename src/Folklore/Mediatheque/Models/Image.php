<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Image as ImageContract;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Traits\HasDimension;

class Image extends Media implements
    ImageContract,
    HasDimensionInterface
{
    use HasDimension;

    protected $table = 'images';

    protected $guarded = [];

    protected $fillable = [
        'handle',
        'name',
        'width',
        'height'
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'width' => 'int',
        'height' => 'int'
    ];

    protected $appends = [
        'original_file',
        'url',
        'type'
    ];

    /**
     * Query scopes
     */
    public function scopeSearch($query, $text)
    {
        $query->where(function ($query) use ($text) {
            $query->orWhere('handle', 'LIKE', '%'.$text.'%');
            $query->orWhere('name', 'LIKE', '%'.$text.'%');
        });
        return $query;
    }

    public function getUrl()
    {
        if ($this instanceof HasFilesInterface) {
            $originalFile = $this->getOriginalFile();
            return $originalFile ? asset($originalFile->getUrl()) : null;
            //return $originalFile ? asset(app('image')->url($originalFile->getUrl(), ['large'])) : null;
        }
        $source = $this->getSource();
        return $source->getUrl($this->path);
    }



    protected function getTypeAttribute()
    {
        return 'image';
    }
}
