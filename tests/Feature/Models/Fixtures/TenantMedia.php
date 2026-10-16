<?php

namespace Folklore\Mediatheque\Tests\Feature\Models\Fixtures;

use Folklore\Mediatheque\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * An application's media model, with a global scope that depends on the
 * request, as a tenant scope would.
 */
class TenantMedia extends Media
{
    public static bool $scoped = false;

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('tenant', function (Builder $query) {
            if (self::$scoped) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
