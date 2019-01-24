<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\PagesCountGetter;
use Folklore\Mediatheque\Contracts\DimensionGetter;
use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Imagick as BaseImagick;
use Exception;
use Illuminate\Support\Facades\Log;

class Imagick implements PagesCountGetter, DimensionGetter, ThumbnailCreatorContract
{
    /**
     * Get pages count of a file
     *
     * @param  string  $path
     * @return int
     */
    public function getPagesCount($path)
    {
        if (!class_exists(BaseImagick::class)) {
            return 0;
        }
        try {
            $image = new BaseImagick($path);
            $pages = $image->getNumberImages();
            $image->destroy();
        } catch (Exception $e) {
            Log::error($e);
            $pages = 0;
        }

        return $pages;
    }

    /**
     * Get dimension
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path)
    {
        if (!class_exists(BaseImagick::class)) {
            return null;
        }
        try {
            $mime = app(MimeGetter::class)->getMime($path);
            $isGif = preg_match('/^image\/(x-)?gif$/', $mime) === 1;
            $image = new BaseImagick($isGif ? $path.'[0]' : $path);
            $dimension = $image->getImageGeometry();
            $image->destroy();
            return $dimension;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    public function createThumbnail($source, $destination)
    {
        try {
            $resolution = config('mediatheque.thumbnails.document.resolution', 150);
            $format = config('mediatheque.thumbnails.document.format', 'jpeg');
            $quality = config('mediatheque.thumbnails.document.quality', 100);
            $backgroundColor = config('mediatheque.thumbnails.document.background', 'white');
            $font = config('mediatheque.thumbnails.document.font');

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
            $image->writeImage($destination.'.'.$format);
            $image->clear();
            $image->destroy();

            return $destination.'.'.$format;
        } catch (Exception $e) {
            Log::error($e);
            return false;
        }
    }
}
