<?php

namespace Folklore\Mediatheque\Services;

use Illuminate\Support\Collection;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Type\Type as Type;
use Folklore\Mediatheque\Contracts\Services\Metadata as MetadataService;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Extension as ExtensionService;
use Folklore\Mediatheque\Contracts\Services\Thumbnail as ThumbnailService;
use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Folklore\Mediatheque\Contracts\Services\DocumentThumbnail;
use Folklore\Mediatheque\Contracts\Services\ImageThumbnail;
use Folklore\Mediatheque\Contracts\Services\VideoThumbnail;
use Folklore\Mediatheque\Contracts\Services\Dimension as DimensionService;
use Folklore\Mediatheque\Contracts\Services\ImageDimension;
use Folklore\Mediatheque\Contracts\Services\VideoDimension;
use Folklore\Mediatheque\Contracts\Services\Duration as DurationService;
use Folklore\Mediatheque\Contracts\Services\AudioDuration;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Illuminate\Support\Facades\Log;
use Exception;

class Metadata implements
    MetadataService,
    MimeService,
    ExtensionService,
    ThumbnailService,
    DimensionService,
    DurationService
{
    protected $mimeTypes;

    public function __construct(MimeTypeGuesserInterface $mimeTypes)
    {
        $this->mimeTypes = $mimeTypes;
    }

    /**
     * Get metadata from path
     *
     * @param  string  $path
     * @return \Folklore\Mediatheque\Metadata\ValuesCollection
     */
    public function getMetadata(string $path, ?Type $type = null): Collection
    {
        $type = is_null($type) ? app(TypeFactory::class)->typeFromPath($path) : $type;
        if (is_null($type)) {
            return collect([]);
        }

        $type = is_string($type) ? mediatheque()->type($type) : $type;
        return $type->metadatas()->reduce(function ($values, $metadata) use ($path) {
            $value = $metadata->getValue($path);
            if (is_null($value)) {
                return $values;
            }
            return $values->{$value instanceof Collection ? 'merge' : 'push'}($value);
        }, collect([]));
    }

    /**
     * Get mime type of a path
     *
     * @param  string  $path
     * @return string
     */
    public function getMime(string $path): ?string
    {
        try {
            $mime = $this->mimeTypes->guessMimeType($path);
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
    public function getExtension(string $path, ?string $filename = null): ?string
    {
        $mime = app(MimeService::class)->getMime($path);
        $types = array_values(config('mediatheque.types'));
        $fileExtension = pathinfo(!empty($filename) ? $filename : $path, PATHINFO_EXTENSION);
        return array_reduce(
            $types,
            function ($extension, $type) use ($mime) {
                $mimes = data_get($type, 'mimes', []);
                return isset($mimes[$mime]) && $mimes[$mime] !== '*' ? $mimes[$mime] : $extension;
            },
            $fileExtension
        );
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
        $mime = $this->getMime($source);
        if (is_null($mime)) {
            return null;
        }
        if (preg_match('/^audio\//', $mime)) {
            return app(AudioThumbnail::class)->getThumbnail($source, $destination, $options);
        } elseif (preg_match('/^video\//', $mime)) {
            return app(VideoThumbnail::class)->getThumbnail($source, $destination, $options);
        } elseif (preg_match('/^image\//', $mime)) {
            return app(ImageThumbnail::class)->getThumbnail($source, $destination, $options);
        }
        return app(DocumentThumbnail::class)->getThumbnail($source, $destination, $options);
    }

    /**
     * Get the dimension of a path
     * @param  string $path The path of a file
     * @return array The dimension
     */
    public function getDimension(string $path): ?array
    {
        $mime = $this->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        if (preg_match('/^image\//', $mime)) {
            return app(ImageDimension::class)->getDimension($path);
        } elseif (preg_match('/^video\//', $mime)) {
            return app(VideoDimension::class)->getDimension($path);
        }
        return null;
    }

    /**
     * Get the duration of a path
     * @param  string $path The path of a file
     * @return float The duration in seconds
     */
    public function getDuration(string $path): ?float
    {
        $mime = $this->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        if (preg_match('/^audio\//', $mime)) {
            return app(AudioDuration::class)->getDuration($path);
        } elseif (preg_match('/^video\//', $mime)) {
            return app(VideoDuration::class)->getDuration($path);
        }
        return null;
    }
}
