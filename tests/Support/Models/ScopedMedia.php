<?php

namespace Folklore\Mediatheque\Tests\Support\Models;

use Folklore\Mediatheque\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * A media model with a global scope, like an application scoping media to a tenant
 */
class ScopedMedia extends Media
{
    protected static function booted()
    {
        parent::booted();

        static::addGlobalScope('visible', function (Builder $query) {
            $query->where('name', 'visible');
        });
    }
}
