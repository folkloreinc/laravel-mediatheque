<?php

namespace Folklore\Mediatheque\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * An application's user, for the routes' tests.
 *
 * @property int $id
 * @property bool $can_upload
 * @property int|null $space_id
 */
class User extends Model implements Authenticatable
{
    use \Illuminate\Auth\Authenticatable;

    protected $table = 'users';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['can_upload' => 'boolean'];
    }
}
