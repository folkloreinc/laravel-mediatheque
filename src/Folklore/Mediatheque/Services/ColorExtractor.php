<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\Color as ColorService;
use Folklore\Mediatheque\Contracts\Services\Palette as PaletteService;
use ColorThief\ColorThief;
use Exception;

class ColorExtractor implements ColorService, PaletteService
{
    protected $format = 'hex';

    protected $quality = 10;

    /**
     * Get the dominant color of a path
     * @param  string $path The path of a file
     * @return mixed The color
     */
    public function getDominantColor(string $path)
    {
        try {
            $palette = ColorThief::getColor($path, $this->quality, null, $this->format);
            return $palette;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get the colors of a path
     * @param  string $path The path of a file
     * @param  int $count The count
     * @return array The colors
     */
    public function getColors(string $path, int $count = 1): ?array
    {
        try {
            $this->getPalette($path, $count);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get the color palette of a path
     * @param  string $path The path of a file
     * @param  int $count The count
     * @return array The palette
     */
    public function getPalette(string $path, int $count = 1): ?array
    {
        try {
            $palette = ColorThief::getPalette($path, $count, $this->quality, null, $this->format);
            return $palette;
        } catch (Exception $e) {
            return null;
        }
    }

    public function setFormat(string $format)
    {
        $this->format = $format; // rgb, hex, int, obj
    }

    public function setQuality(int $quality)
    {
        $this->quality = $quality; // 1 to 10
    }
}
