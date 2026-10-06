<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Mime
{
    /**
     * Get the mime of a file
     */
    public function getMime(string $path): ?string;
}
