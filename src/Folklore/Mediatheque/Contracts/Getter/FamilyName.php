<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface FamilyName
{
    /**
     * Get family name from a file
     *
     * @param  string  $path
     * @return array
     */
    public function getFamilyName($path);
}
