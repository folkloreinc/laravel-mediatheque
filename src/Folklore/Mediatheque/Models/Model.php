<?php namespace Folklore\Mediatheque\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Folklore\Mediatheque\Models\Observers\MediaObserver;

class Model extends Eloquent
{
    public function __construct(array $attributes = array())
    {
        $this->table = config('mediatheque.table_prefix').$this->table;
        parent::__construct($attributes);
    }

    public static function boot()
    {
        parent::boot();

        static::observe(MediaObserver::class);
    }
}
