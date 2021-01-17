<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Extension
{
    /**
     * Get the extension of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getExtension(string $path, ?string $filename = null): ?string;
}
