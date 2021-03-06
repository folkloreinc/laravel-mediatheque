<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasDimension;
use Folklore\Mediatheque\Support\Traits\HasUrl;

class Picture extends Model implements
    PictureContract,
    HasFilesInterface,
    HasDimensionInterface,
    HasUrlInterface
{
    use HasFiles, HasDimension, HasUrl;

    protected $table = 'pictures';

    protected $guarded = [];
    protected $fillable = [
        'handle',
        'name',
        'width',
        'height'
    ];

    protected $casts = array(
        'handle' => 'string',
        'name' => 'string',
        'width' => 'int',
        'height' => 'int'
    );

    protected $appends = array(
        'original_file',
        'url',
        'type',
        'dimension_human'
    );

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
        return 'picture';
    }
}
