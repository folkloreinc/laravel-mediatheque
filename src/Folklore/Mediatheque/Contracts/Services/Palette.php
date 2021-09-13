<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Palette
{
    /**
     * Get the color palette of a path
     * @param  string $path The path of a file
     * @param  int $path The path of a file
     * @return array The palette
     */
    public function getPalette(string $path, int $count = 1): ?array;
}
