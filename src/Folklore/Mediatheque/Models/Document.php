<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Model\Document as DocumentContract;
use Folklore\Mediatheque\Support\Interfaces\HasPages as HasPagesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Traits\HasPages;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Files\Thumbnails;

class Document extends Media implements
    DocumentContract,
    HasPagesInterface,
    HasThumbnailsInterface
{
    use HasPages, HasThumbnails;

    protected $table = 'documents';

    protected $guarded = [];

    protected $fillable = [
        'handle',
        'name',
        'pages'
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string',
        'pages' => 'int',
    ];

    protected $appends = [
        'original_file',
        'thumbnails',
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

    protected function getTypeAttribute()
    {
        return 'document';
    }
}
