<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\AnimatedImage;

class Gif implements AnimatedImage
{
    /**
     * Check if a gif is animated
     *
     * @param  string  $path
     * @return bool
     */
    public function isAnimated(string $path): bool
    {
        return $this->framesCount($path) > 1;
    }

    /**
     * Get the number of frames of a gif
     *
     * @param  string  $path
     * @return int|null
     */
    public function framesCount(string $path): ?int
    {
        $fp = fopen($path, 'rb');

        if (fread($fp, 3) !== 'GIF') {
            fclose($fp);
            return null;
        }

        $frames = 0;

        while (!feof($fp) && $frames < 2) {
            if (fread($fp, 1) === "\x00") {
                /* Some of the animated GIFs do not contain graphic control extension (starts with 21 f9) */
                if (fread($fp, 1) === "\x21" || fread($fp, 2) === "\x21\xf9") {
                    $frames++;
                }
            }
        }

        fclose($fp);

        return $frames;
    }
}
