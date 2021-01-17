<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface FontFamilyName
{
    /**
     * Get the font family name of a path
     * @param  string $path The path of a file
     * @return string The font family name
     */
    public function getFontFamilyName(string $path): ?string;
}
