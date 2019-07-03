<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface PathFormatter
{
    /**
     * Format a path with replacements
     * @param  string $format The format of the path
     * @param  array $params The associative array to use as replacement value
     * @return string
     */
    public function formatPath($format, ...$params);
}
