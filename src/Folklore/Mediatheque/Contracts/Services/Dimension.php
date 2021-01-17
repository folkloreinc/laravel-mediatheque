<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Dimension
{
    /**
     * Get the dimension of a path
     * @param  string $path The path of a file
     * @return array The dimension
     */
    public function getDimension(string $path): ?array;
}
