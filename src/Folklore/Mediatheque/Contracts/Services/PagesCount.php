<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface PagesCount
{
    /**
     * Get the pages count of a path
     * @param  string $path The path of a file
     * @return integer The number of pages
     */
    public function getPagesCount(string $path): ?int;
}
