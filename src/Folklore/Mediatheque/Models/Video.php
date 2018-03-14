<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Model\Video as VideoContract;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Traits\HasDuration;
use Folklore\Mediatheque\Support\Traits\HasDimension;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Files\Mp4;
use Folklore\Mediatheque\Files\Thumbnails;

class Video extends Media implements
    VideoContract,
    HasDurationInterface,
    HasDimensionInterface,
    HasThumbnailsInterface
{
    use HasDuration, HasDimension, HasThumbnails;

    protected $table = 'videos';

    protected $guarded = [];

    protected $fillable = [
        'handle',
        'name',
        'width',
        'height',
        'duration'
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'width' => 'int',
        'height' => 'int',
        'duration' => 'float'
    ];

    protected $appends = [
        'original_file',
        'thumbnails',
        'url',
        'duration_human',
        'type'
    ];

    public function getUrl()
    {
        if ($this instanceof HasFilesInterface) {
            $h264 = $this->files->h264;
            if ($h264) {
                return $h264->getUrl();
            }
            $originalFile = $this->getOriginalFile();
            return $originalFile ? $originalFile->getUrl() : null;
        }
        $source = $this->getSource();
        return $source->getUrl($this->path);
    }

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



    protected function getTypeAttribute()
    {
        return 'video';
    }
}
