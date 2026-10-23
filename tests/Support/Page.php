<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Concerns\HasMedias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

    public static function createTable(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }
}
