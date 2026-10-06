<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface AnimatedImage
{
    /**
     * Check if a gif is animated
     */
    public function isAnimated(string $path): bool;

    /**
     * Get the number of frames of a gif
     */
    public function framesCount(string $path): ?int;
}
