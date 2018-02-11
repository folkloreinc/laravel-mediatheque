<?php namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Document as DocumentContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPages as HasPagesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Interfaces\HasThumbnails as HasThumbnailsInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasPages;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasThumbnails;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Files\Thumbnails;

class Document extends Model implements
    DocumentContract,
    HasFilesInterface,
    HasPagesInterface,
    HasUrlInterface,
    HasThumbnailsInterface,
    HasPipelinesInterface
{
    use HasFiles, HasPages, HasUrl, HasThumbnails, HasPipelines;

    protected $table = 'documents';

    protected $guarded = array();
    protected $fillable = array(
        'handle',
        'name',
        'pages'
    );

    protected $casts = array(
        'handle' => 'string',
        'name' => 'string',
        'pages' => 'int',
    );

    protected $appends = array(
        'original_file',
        'thumbnails',
        'url',
        'type'
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

    protected function getTypeAttribute()
    {
        return 'document';
    }
}
