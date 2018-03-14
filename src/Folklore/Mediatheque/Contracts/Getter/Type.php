<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Type
{
    /**
     * Get type of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getType($path);
}
