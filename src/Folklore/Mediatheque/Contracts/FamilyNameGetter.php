<?php

namespace Folklore\Mediatheque\Contracts;

interface FamilyNameGetter
{
    /**
     * Get family name from a file
     *
     * @param  string  $path
     * @return array
     */
    public function getFamilyName($path);
}
