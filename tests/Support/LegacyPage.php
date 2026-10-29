<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Support\Traits\HasMedias;
use Illuminate\Database\Eloquent\Model;

/**
 * A page of a 1.x application, which used the 1.x name of the trait.
 *
 * @property int $id
 * @property string $title
 */
class LegacyPage extends Model
{
    use HasMedias;

    protected $table = 'pages';

    public $timestamps = false;

    protected $guarded = [];
}
