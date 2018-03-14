<?php

namespace Folklore\Mediatheque\Services;

use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Folklore\Mediatheque\Contracts\Getter\Metadata as MetadataGetter;
use Folklore\Mediatheque\Contracts\Getter\Mime as MimeGetter;
use Folklore\Mediatheque\Contracts\Getter\Extension as ExtensionGetter;
use Folklore\Mediatheque\Contracts\Getter\Type as TypeGetter;
use Folklore\Mediatheque\Contracts\Getter\Dimension as DimensionGetter;
use Folklore\Mediatheque\Contracts\Getter\Duration as DurationGetter;
use Folklore\Mediatheque\Contracts\Getter\PagesCount as PagesCountGetter;
use Folklore\Mediatheque\Contracts\Getter\FamilyName as FamilyNameGetter;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasFamilyName as HasFamilyNameInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPages as HasPagesInterface;
use Exception;
use Illuminate\Support\Facades\Log;

class Metadata implements
    MetadataGetter,
    MimeGetter,
    ExtensionGetter,
    TypeGetter,
    DimensionGetter,
    DurationGetter,
    PagesCountGetter,
    FamilyNameGetter
{
    /**
     * Get metadata
     *
     * @param  string  $path
     * @return array
     */
    public function getMetadata($path)
    {
        $type = $this->getType($path);
        if (is_null($type)) {
            return [];
        }

        $metadata = [];
        $model = $type->getModel();
        if ($model instanceof HasDurationInterface) {
            $duration = app(DurationGetter::class)->getDuration($path);
            if ($duration) {
                $metadata['duration'] = $duration;
            }
        }

        if ($model instanceof HasFamilyNameInterface) {
            $familyName = app(FamilyNameGetter::class)->getFamilyName($path);
            if ($familyName) {
                $metadata['family_name'] = $familyName;
            }
        }

        if ($model instanceof HasDimensionInterface) {
            $dimension = app(DimensionGetter::class)->getDimension($path);
            if ($dimension) {
                $metadata['width'] = $dimension['width'];
                $metadata['height'] = $dimension['height'];
            }
        }

        if ($model instanceof HasPagesInterface) {
            $pages = app(PagesCountGetter::class)->getPagesCount($path);
            if ($pages) {
                $metadata['pages'] = $pages;
            }
        }

        return $metadata;
    }

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
                $types = array_values(config('mediatheque.types'));
                $fileExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                foreach ($types as $key => $type) {
                    foreach ($type['mimes'] as $mimeType => $extension) {
                        if ($fileExtension === $extension) {
                            return $mimeType;
                        }
                    }
                }
            }
            return $mime;
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
     * Get extension of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getExtension($path, $filename = null)
    {
        $mime = app(MimeGetter::class)->getMime($path);
        $types = array_values(config('mediatheque.types'));
        $fileExtension = pathinfo(!empty($filename) ? $filename : $path, PATHINFO_EXTENSION);
        return array_reduce($types, function ($extension, $type) use ($mime) {
            $mimes = array_get($type, 'mimes', []);
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
        $fileMime = app(MimeGetter::class)->getMime($path);
        $types = mediatheque()->types();
        foreach ($types as $type) {
            if ($type->isType($path, $mime)) {
                return $type->getName();
            }
        }
        return null;
    }
}
