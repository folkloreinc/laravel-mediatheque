<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\PagesCount;
use Folklore\Mediatheque\Contracts\Services\ImageDimension;
use Folklore\Mediatheque\Contracts\Services\ImageThumbnail;
use Folklore\Mediatheque\Contracts\Services\DocumentThumbnail;
use Imagick as BaseImagick;
use Exception;
use Illuminate\Support\Facades\Log;

class Imagick implements
    PagesCount,
    ImageDimension,
    ImageThumbnail,
    DocumentThumbnail
{
    /**
     * Get pages count of a file
     *
     * @param  string  $path
     * @return int
     */
    public function getPagesCount($path): ?int
    {
        if (!class_exists(BaseImagick::class)) {
            return 0;
        }
        try {
            $image = new BaseImagick($path);
            $pages = $image->getNumberImages();
            $image->destroy();
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
            return null;
        }

        return $pages;
    }

    /**
     * Get dimension
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path): ?array
    {
        if (!class_exists(BaseImagick::class)) {
            return null;
        }
        try {
            $image = new BaseImagick($path);
            $dimension = $image->getImageGeometry();
            $image->destroy();
            return $dimension;
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
            return null;
        }
    }

    /**
     * Get the thumbnail of a path
     * @param  string $source The source path
     * @param  string $destination The destination path
     * @param  array $options The options
     * @return string The path of the thumbnail
     */
    public function getThumbnail(string $source, string $destination, array $options = []): ?string
    {
        $resolution = data_get($options, 'resolution', 150);
        $format = data_get($options, 'format', 'jpeg');
        $quality = data_get($options, 'quality', 100);
        $backgroundColor = data_get($options, 'background', 'white');
        $font = data_get($options, 'font');

        $image = new BaseImagick();
        $image->setResolution($resolution, $resolution);
        $image->readImage($source);
        $image->setImageFormat($format);
        $image->setImageCompressionQuality($quality);
        if (!empty($backgroundColor)) {
            $image->setImageBackgroundColor($backgroundColor);
        }
        if (!empty($font) && file_exists($font)) {
            $image->setFont($font);
        }
        $image->writeImage($destination);
        $image->clear();
        $image->destroy();

        return $destination;
    }
}
