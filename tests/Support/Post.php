<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Concerns\HasMedias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An application's model that attaches media, and is soft-deleted.
 *
 * @property int $id
 * @property string $title
 */
class Post extends Model
{
    use HasMedias;
    use SoftDeletes;

    protected $table = 'posts';

    protected $guarded = [];
}
