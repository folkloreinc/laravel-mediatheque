<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Concerns\HasMedias;
use Illuminate\Database\Eloquent\Model;

/**
 * An application's model that attaches media and references them in its
 * JSON data, as `laravel-folklore`'s pages do.
 *
 * @property int $id
 * @property string $title
 */
class Page extends Model
{
    use HasMedias;

    protected $table = 'pages';

    protected $guarded = [];

    public $timestamps = false;
}
