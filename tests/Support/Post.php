<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Concerns\HasMedias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

    public static function createTable(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
