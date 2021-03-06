<?php

namespace Folklore\Mediatheque\Services;

use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Folklore\Mediatheque\Contracts\MimeGetter;
use Folklore\Mediatheque\Contracts\ExtensionGetter;
use Folklore\Mediatheque\Contracts\TypeGetter;
use Folklore\Mediatheque\Contracts\DimensionGetter;
use Folklore\Mediatheque\Contracts\DurationGetter;
use Folklore\Mediatheque\Contracts\PagesCountGetter;
use Folklore\Mediatheque\Contracts\FamilyNameGetter;
use Exception;

class MediaInfo implements
    MimeGetter,
    ExtensionGetter,
    TypeGetter,
    DimensionGetter,
    DurationGetter,
    PagesCountGetter,
    FamilyNameGetter
{
    /**
     * Get pages count
     *
     * @param  string  $path
     * @return int
     */
    public function getPagesCount($path)
    {
        return app('mediatheque.services.pagescount')->getPagesCount($path);
    }

    /**
     * Get family name of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getFamilyName($path)
    {
        return app('mediatheque.services.familyname')->getFamilyName($path);
    }

    /**
     * Get dimension
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path)
    {
        $type = $this->getType($path);
        return $type ? app('mediatheque.services.dimension.'.$type)->getDimension($path) : null;
    }

    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getDuration($path)
    {
        $type = $this->getType($path);
        return $type ? app('mediatheque.services.duration.'.$type)->getDuration($path) : 0;
    }

    /**
     * Get mime type of a path
     *
     * @param  string  $path
     * @return string
     */
    public function getMime($path)
    {
        try {
            $mime = MimeTypeGuesser::getInstance()->guess($path);
            if ($mime === 'application/octet-stream') {
                $typeMimes = array_values(config('mediatheque.mimes'));
                $fileExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                foreach ($typeMimes as $type => $mimes) {
                    foreach ($mimes as $mimeType => $extension) {
                        if ($fileExtension === $extension) {
                            return $mimeType;
                        }
                    }
                }
            }
            return $mime;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get extension of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getExtension($path, $filename = null)
    {
        $mime = app(MimeGetter::class)->getMime($path);
        $typeMimes = array_values(config('mediatheque.mimes'));
        $fileExtension = pathinfo(!empty($filename) ? $filename : $path, PATHINFO_EXTENSION);
        return array_reduce($typeMimes, function ($extension, $mimes) use ($mime) {
            return isset($mimes[$mime]) && $mimes[$mime] !== '*' ? $mimes[$mime] : $extension;
        }, $fileExtension);
    }

    /**
     * Get type of a path
     *
     * @param  string  $path
     * @return string
     */
    public function getType($path)
    {
        $mime = app(MimeGetter::class)->getMime($path);
        $mimesByType = config('mediatheque.mimes');
        foreach ($mimesByType as $type => $mimes) {
            if (isset($mimes[$mime])) {
                return $type;
            }
        }
        return null;
    }
}
