<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Model\Audio as AudioContract;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Traits\HasDuration;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Files\Thumbnails;

class Audio extends Media implements
    AudioContract,
    HasDurationInterface,
    HasThumbnailsInterface
{
    use HasDuration, HasThumbnails;

    protected $table = 'audios';

    protected $guarded = [];

    protected $fillable = [
        'handle',
        'name',
        'duration',
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'duration' => 'float'
    ];

    protected $appends = [
        'type',
        'original_file',
        'thumbnails',
        'url',
        'duration_human'
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
}
