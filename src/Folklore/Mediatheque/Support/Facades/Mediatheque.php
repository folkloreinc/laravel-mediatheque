<?php namespace Folklore\Mediatheque\Support\Facades;

use Illuminate\Support\Facades\Facade;

class Mediatheque extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'mediatheque';
    }
}
