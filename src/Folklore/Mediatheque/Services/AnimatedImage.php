<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\AnimatedImage as AnimatedImageService;
use Folklore\Mediatheque\Contracts\Services\Gif as GifService;
use Folklore\Mediatheque\Contracts\Services\Webp as WebpService;

class AnimatedImage implements AnimatedImageService
{
    protected $mimeService;

    public function __construct(MimeService $mimeService)
    {
        $this->mimeService = $mimeService;
    }

    /**
     * Check if a gif is animated
     *
     * @param  string  $path
     * @return bool
     */
    public function isAnimated(string $path): bool
    {
        $mime = $this->mimeService->getMime($path);
        switch ($mime) {
            case 'image/webp':
                return resolve(WebpService::class)->isAnimated($path);
            case 'image/x-gif':
            case 'image/gif':
                return resolve(GifService::class)->isAnimated($path);
        }
        return false;
    }

    /**
     * Get the number of frames of a gif
     *
     * @param  string  $path
     * @return int|null
     */
    public function framesCount(string $path): ?int
    {
        $mime = $this->mimeService->getMime($path);
        switch ($mime) {
            case 'image/webp':
                return resolve(WebpService::class)->framesCount($path);
            case 'image/x-gif':
            case 'image/gif':
                return resolve(GifService::class)->framesCount($path);
        }
        return null;
    }
}
