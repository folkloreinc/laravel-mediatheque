<?php

namespace Folklore\Mediatheque\Contracts;

interface TypeGetter
{
    /**
     * Get type of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getType($path);
}
