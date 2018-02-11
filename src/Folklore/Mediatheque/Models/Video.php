<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Video as VideoContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasDuration;
use Folklore\Mediatheque\Support\Traits\HasDimension;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Files\Mp4;
use Folklore\Mediatheque\Files\Thumbnails;

class Video extends Model implements
    VideoContract,
    HasFilesInterface,
    HasDurationInterface,
    HasDimensionInterface,
    HasUrlInterface,
    HasThumbnailsInterface,
    HasPipelinesInterface
{
    use HasFiles, HasDuration, HasDimension, HasUrl, HasThumbnails, HasPipelines;

    protected $table = 'videos';

    protected $guarded = array();
    protected $fillable = array(
        'handle',
        'name',
        'width',
        'height',
        'duration'
    );

    protected $casts = array(
        'handle' => 'string',
        'name' => 'string',
        'width' => 'int',
        'height' => 'int',
        'duration' => 'float'
    );

    protected $appends = array(
        'original_file',
        'thumbnails',
        'url',
        'duration_human',
        'type'
    );

    public function getUrl()
    {
        if ($this instanceof HasFilesInterface) {
            $mp4 = $this->files->mp4;
            if ($mp4) {
                return $this->files->mp4->getUrl();
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
