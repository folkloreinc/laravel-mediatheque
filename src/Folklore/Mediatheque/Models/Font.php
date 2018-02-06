<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Font as FontContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasFamilyName as HasFamilyNameInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasFamilyName;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasPipelines;
use Folklore\Mediatheque\Files\WebFonts;

class Font extends Model implements
    FontContract,
    HasFilesInterface,
    HasFamilyNameInterface,
    HasUrlInterface,
    HasPipelinesInterface
{
    use HasFiles, HasFamilyName, HasUrl, HasPipelines;

    protected $table = 'fonts';

    protected $guarded = [];
    protected $fillable = [
        'handle',
        'name',
        'family_name'
    ];

    protected $casts = array(
        'handle' => 'string',
        'name' => 'string'
    );

    protected $appends = array(
        'original_file',
        'url',
        'type'
    );

    protected $filesCreators = [
        'webfont' => WebFonts::class,
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
        return 'font';
    }
}
