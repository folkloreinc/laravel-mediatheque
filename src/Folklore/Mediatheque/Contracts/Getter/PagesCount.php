<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface PagesCount
{
    /**
     * Get the pages count of a file
     *
     * @param  string  $path
     * @return int
     */
    public function getPagesCount($path);
}
