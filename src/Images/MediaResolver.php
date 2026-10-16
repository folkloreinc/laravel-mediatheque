<?php

namespace Folklore\Mediatheque\Images;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\ImageSource;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The `media` resolver of `laravel-image` 2 (docs/decisions/0013): image
 * URLs name media, never storage paths.
 *
 * - `med_…` resolves to the media's image: the original of an `image`, the
 *   thumbnail of other types (the poster first). `med_…/{handle}` and
 *   `med_…/{handle}/{index}` resolve to one of its files.
 * - Only stored image files resolve, once their checksum is known: the
 *   version is its first 8 hex characters, so new content gets new URLs.
 * - The disk is the file's source, a Laravel disk.
 * - Deleted media don't resolve. Pending and failed media resolve to the
 *   image files they have. A key from a URL is resolved without the
 *   application's global scopes: the URL's signature authorizes it.
 * - `isPrivate()` follows the media's visibility.
 */
class MediaResolver implements SourceResolver
{
    public const string NAME = 'media';

    public function __construct(
        private readonly MediathequeConfig $config,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * The `Media` contract, a `MediaReference`, `media://med_…`, a uid, or a
     * key with a handle (`med_…/poster`). Checks the form only.
     */
    public function supports(mixed $reference): bool
    {
        return match (true) {
            $reference instanceof Media => true,
            $reference instanceof MediaReference => ! $reference->isLegacy(),
            is_string($reference) => $this->parse($reference) !== null,
            default => false,
        };
    }

    public function resolve(mixed $reference): ImageSource
    {
        if ($reference instanceof Media) {
            return $this->source($reference, null, null);
        }

        $key = match (true) {
            $reference instanceof MediaReference && ! $reference->isLegacy() => (string) $reference->uid,
            is_string($reference) && $this->supports($reference) => $reference,
            default => throw new UnsupportedReference(sprintf('The media resolver doesn\'t support %s.', get_debug_type($reference))),
        };

        return $this->resolveKey(str_starts_with($key, MediaReference::SCHEME.'://') ? substr($key, strlen(MediaReference::SCHEME) + 3) : $key);
    }

    public function resolveKey(string $key): ImageSource
    {
        [$uid, $handle, $index] = $this->parse($key) ?? throw new SourceNotFound(sprintf('"%s" is not a media key.', $key));

        $query = Models::media()::query()->withoutGlobalScopes()->with('mediaFiles')->where('uid', $uid);
        $model = $query->getModel();
        $deletedAt = in_array(SoftDeletes::class, class_uses_recursive($model), true) && method_exists($model, 'getQualifiedDeletedAtColumn')
            ? $model->getQualifiedDeletedAtColumn()
            : null;
        if (is_string($deletedAt)) {
            $query->whereNull($deletedAt);
        }

        $media = $query->first() ?? throw new SourceNotFound(sprintf('The media %s doesn\'t exist.', $uid));

        return $this->source($media, $handle, $index);
    }

    /**
     * The uid, handle and index of a key or reference, or null when it is
     * not one.
     *
     * @return array{string, string|null, int|null}|null
     */
    private function parse(string $value): ?array
    {
        if (str_starts_with($value, MediaReference::SCHEME.'://')) {
            $value = substr($value, strlen(MediaReference::SCHEME) + 3);
        }

        $segments = explode('/', $value);
        if (count($segments) > 3 || ! Uid::isValid($segments[0], $this->config->mediaPrefix())) {
            return null;
        }

        $handle = $segments[1] ?? null;
        if ($handle !== null && $handle !== File::ORIGINAL && ! Rendition::isValidHandle($handle)) {
            return null;
        }

        $index = $segments[2] ?? null;
        if ($index !== null && preg_match('/^(0|[1-9][0-9]{0,5})$/', $index) !== 1) {
            return null;
        }

        return [$segments[0], $handle, $index !== null ? (int) $index : null];
    }

    private function source(Media $media, ?string $handle, ?int $index): ImageSource
    {
        $file = match (true) {
            $handle !== null => $media->file($handle, $index),
            $media->type() === 'image' => $media->original(),
            default => $media->thumbnail(),
        };

        $source = $file?->source();
        $path = $file?->path();
        $checksum = $file?->checksum();

        if ($file === null || $file->isRemote() || $source === null || $path === null || $checksum === null || ! str_starts_with((string) $file->mime(), 'image/')) {
            throw new SourceNotFound(sprintf('The media %s has no image file for this key.', $media->uid()));
        }

        $width = $file->width();
        $height = $file->height();
        $sized = $width !== null && $height !== null && $width > 0 && $height > 0;
        $placeholder = $file->metadata('thumbhash') ?? ($file->handle() === File::ORIGINAL ? $media->metadata('thumbhash') : null);

        return new ImageSource(
            resolver: self::NAME,
            key: $media->uid().($handle !== null ? '/'.$handle.($index !== null ? '/'.$index : '') : ''),
            version: substr($checksum, 0, 8),
            disk: $source,
            path: $path,
            width: $sized ? $width : null,
            height: $sized ? $height : null,
            mime: $file->mime(),
            placeholder: is_string($placeholder) ? $placeholder : null,
            private: $media->visibility() === Visibility::Private,
        );
    }
}
