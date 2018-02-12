<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Font as FontContract;
use Folklore\Mediatheque\Support\Interfaces\HasFamilyName as HasFamilyNameInterface;
use Folklore\Mediatheque\Support\Traits\HasFamilyName;

class Font extends Media implements
    FontContract,
    HasFamilyNameInterface
{
    use HasFamilyName;

    protected $table = 'fonts';

    protected $guarded = [];

    protected $fillable = [
        'handle',
        'name',
        'family_name'
    ];

    protected $casts = [
        'handle' => 'string',
        'name' => 'string'
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

    protected function getTypeAttribute()
    {
        return 'font';
    }
}
