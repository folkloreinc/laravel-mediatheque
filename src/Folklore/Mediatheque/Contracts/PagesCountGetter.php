<?php

namespace Folklore\Mediatheque\Contracts;

interface PagesCountGetter
{
    /**
     * Get the pages count of a file
     *
     * @param  string  $path
     * @return int
     */
    public function getPagesCount($path);
}
