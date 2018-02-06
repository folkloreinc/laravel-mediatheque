<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Audio as AudioContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasDuration;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Files\Thumbnails;

class Audio extends Model implements
    AudioContract,
    HasFilesInterface,
    HasDurationInterface,
    HasUrlInterface,
    HasThumbnailsInterface,
    HasPipelinesInterface
{
    use HasFiles, HasDuration, HasUrl, HasThumbnails, HasPipelines;

    protected $table = 'audios';

    protected $guarded = array();
    protected $fillable = array(
        'handle',
        'name',
        'duration',
    );

    protected $casts = array(
        'handle' => 'string',
        'name' => 'string',
        'duration' => 'float'
    );

    protected $appends = array(
        'type',
        'original_file',
        'thumbnails',
        'url',
        'duration_human'
    );

    public function filesCreators()
    {
        $filesCreators = [];
        if (config('mediatheque.thumbnails.enable') && config('mediatheque.thumbnails.audio.enable')) {
            $filesCreators['thumbnail'] = Thumbnails::class;
        }
        return sizeof($filesCreators) ?
            $filesCreators : null;
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
        return 'audio';
    }
}
