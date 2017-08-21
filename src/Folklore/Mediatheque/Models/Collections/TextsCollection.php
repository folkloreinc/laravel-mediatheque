<?php namespace Folklore\Mediatheque\Models\Collections;

use Illuminate\Database\Eloquent\Collection;

class TextsCollection extends Collection
{

    /*
    *
    * Get a specific locale
    *
    */
    public function __get($key)
    {
        $first = $this->first();
        if ($first) {
            return $first->{$key} ? $first->{$key}:($first->locales->{$key} ? $first->locales->{$key}:null);
        }
        return null;
    }
}
