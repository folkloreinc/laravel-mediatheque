<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Color
{
    /**
     * Get the colors of a path
     * @param  string $path The path of a file
     * @param  int $path The path of a file
     * @return array The colors
     */
    public function getColors(string $path, int $count = 1): ?array;
}
