<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Extension
{
    /**
     * Get the extension of a file
     */
    public function getExtension(string $path, ?string $filename = null): ?string;
}
