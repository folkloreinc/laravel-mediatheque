<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface AnimatedImage
{
    /**
     * Check if a gif is animated
     *
     * @param  string  $path
     * @return bool
     */
    public function isAnimated(string $path): bool;

    /**
     * Get the number of frames of a gif
     *
     * @param  string  $path
     * @return int|null
     */
    public function framesCount(string $path): ?int;
}
