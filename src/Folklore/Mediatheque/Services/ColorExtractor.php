<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\Color as ColorService;
use Folklore\Mediatheque\Contracts\Services\Palette as PaletteService;

use League\ColorExtractor\Color;
use League\ColorExtractor\ColorExtractor as BaseColorExtractor;
use League\ColorExtractor\Palette;

class ColorExtractor implements ColorService, PaletteService
{
    /**
     * Get the colors of a path
     * @param  string $path The path of a file
     * @param  int $path The path of a file
     * @return array The colors
     */
    public function getColors(string $path, int $count = 1): ?array
    {
        $palette = Palette::fromFilename($path);
        return $this->getHex($palette->getMostUsedColors($count));
    }

    /**
     * Get the color palette of a path
     * @param  string $path The path of a file
     * @param  int $path The path of a file
     * @return array The palette
     */
    public function getPalette(string $path, int $count = 1): ?array
    {
        $palette = Palette::fromFilename($path);
        $extractor = new BaseColorExtractor($palette);
        return $this->getHex($extractor->extract($count));
    }

    protected function getHex($colors)
    {
        return collect($colors)
            ->map(function ($color) {
                return Color::fromIntToHex($color);
            })
            ->toArray();
    }
}
