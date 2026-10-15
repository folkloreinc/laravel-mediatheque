<?php

namespace Folklore\Mediatheque\Services;

use Exception;
use Folklore\Mediatheque\Contracts\Services\AnimatedImage as AnimatedImageService;
use Folklore\Mediatheque\Contracts\Services\AudioDuration;
use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Folklore\Mediatheque\Contracts\Services\Dimension as DimensionService;
use Folklore\Mediatheque\Contracts\Services\DocumentThumbnail;
use Folklore\Mediatheque\Contracts\Services\Duration as DurationService;
use Folklore\Mediatheque\Contracts\Services\Extension as ExtensionService;
use Folklore\Mediatheque\Contracts\Services\ImageDimension;
use Folklore\Mediatheque\Contracts\Services\ImageThumbnail;
use Folklore\Mediatheque\Contracts\Services\Metadata as MetadataService;
use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Svg;
use Folklore\Mediatheque\Contracts\Services\SvgSanitizer;
use Folklore\Mediatheque\Contracts\Services\Thumbnail as ThumbnailService;
use Folklore\Mediatheque\Contracts\Services\VideoDimension;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Folklore\Mediatheque\Contracts\Services\VideoThumbnail;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Type\Type;
use Folklore\Mediatheque\Metadata\ValuesCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\MimeTypesInterface;

class Metadata implements DimensionService, DurationService, ExtensionService, MetadataService, MimeService, ThumbnailService
{
    /**
     * Extensions that a web server may execute or a browser may render as an
     * active document
     */
    public const BLOCKED_EXTENSIONS = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml',
        'phar', 'phps', 'pgif', 'inc', 'cgi', 'fcgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'asa', 'ascx', 'ashx', 'asmx', 'axd', 'cer', 'config', 'jsp', 'jspx',
        'jsw', 'jsv', 'jspf', 'cfm', 'cfml', 'htaccess', 'htpasswd', 'html', 'htm', 'xhtml',
        'xht', 'shtml', 'shtm', 'stm', 'mht', 'mhtml', 'hta', 'xml', 'xsl', 'xslt', 'svg',
        'svgz', 'js', 'mjs', 'swf',
    ];

    /**
     * Mimes of files that are not identified by their content
     */
    public const GENERIC_MIMES = ['application/octet-stream', 'text/plain'];

    protected $mimeTypes;

    public function __construct(MimeTypeGuesserInterface $mimeTypes)
    {
        $this->mimeTypes = $mimeTypes;
    }

    /**
     * Get metadata from path
     *
     * @return ValuesCollection
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
     * The extension comes from the file's content. The extension of the file
     * name, which a client controls, is only kept when it matches the content,
     * or when the content is generic and the extension is not blocked.
     */
    public function getExtension(string $path, ?string $filename = null): ?string
    {
        $mime = app(MimeService::class)->getMime($path);
        $types = array_values(config('mediatheque.types'));
        $fileExtension = strtolower(pathinfo(! empty($filename) ? $filename : $path, PATHINFO_EXTENSION));

        // An extension mapped to the mime in the types config is trusted
        $typeExtension = array_reduce(
            $types,
            function ($extension, $type) use ($mime) {
                $mimes = data_get($type, 'mimes', []);

                return isset($mimes[$mime]) && $mimes[$mime] !== '*' ? $mimes[$mime] : $extension;
            },
            null
        );
        if (! is_null($typeExtension)) {
            return $typeExtension;
        }

        // SVG files are sanitized before they are stored
        if (in_array($mime, SvgSanitizer::MIMES, true)) {
            return 'svg';
        }

        $mimeExtensions = ! is_null($mime) ? $this->getMimeTypes()->getExtensions($mime) : [];
        $safeMimeExtensions = array_values(array_filter($mimeExtensions, function ($extension) {
            return ! $this->isBlockedExtension($extension);
        }));
        if (in_array($fileExtension, $safeMimeExtensions, true)) {
            return $fileExtension;
        }

        $isGenericMime = is_null($mime) || in_array($mime, self::GENERIC_MIMES, true) || empty($mimeExtensions);
        if (! $isGenericMime && count($safeMimeExtensions) > 0) {
            return $safeMimeExtensions[0];
        }

        if (! $this->isBlockedExtension($fileExtension)) {
            return $fileExtension;
        }

        return count($safeMimeExtensions) > 0 ? $safeMimeExtensions[0] : 'bin';
    }

    /**
     * Check if an extension must not be kept from a client's file name
     */
    public function isBlockedExtension(string $extension): bool
    {
        $extension = strtolower($extension);
        if (preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            return true;
        }

        $blockedExtensions = config('mediatheque.uploads.blocked_extensions');

        return in_array(
            $extension,
            is_array($blockedExtensions) ? $blockedExtensions : self::BLOCKED_EXTENSIONS,
            true
        );
    }

    protected function getMimeTypes(): MimeTypesInterface
    {
        return $this->mimeTypes instanceof MimeTypesInterface
            ? $this->mimeTypes
            : MimeTypes::getDefault();
    }

    /**
     * Get the thumbnail of a path
     *
     * @param  string  $source  The source path
     * @param  string  $destination  The destination path
     * @param  array  $options  The options
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
     *
     * @param  string  $path  The path of a file
     * @return array The dimension
     */
    public function getDimension(string $path): ?array
    {
        $mime = $this->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        if (preg_match('/^image\/svg\+xml/', $mime) === 1) {
            return app(Svg::class)->getDimension($path);
        } elseif (preg_match('/^image\//', $mime)) {
            return app(ImageDimension::class)->getDimension($path);
        } elseif (preg_match('/^video\//', $mime)) {
            return app(VideoDimension::class)->getDimension($path);
        }

        return null;
    }

    /**
     * Get the duration of a path
     *
     * @param  string  $path  The path of a file
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
        } elseif (preg_match('/^image\//', $mime) && resolve(AnimatedImageService::class)->isAnimated($path)) {
            return app(VideoDuration::class)->getDuration($path);
        }

        return null;
    }
}
