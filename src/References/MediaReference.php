<?php

namespace Folklore\Mediatheque\References;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Exceptions\InvalidReference;
use Folklore\Mediatheque\Support\Uid;
use Stringable;

/**
 * A reference to a media, as stored in rich text and JSON data
 * (docs/decisions/0003):
 *
 * - `media://med_01JAB3…`, by uid: the 2.0 form;
 * - `medias://123`, by internal id: the 1.x form, stored by laravel-folklore,
 *   which keeps resolving.
 */
final readonly class MediaReference implements Stringable
{
    public const string SCHEME = 'media';

    public const string LEGACY_SCHEME = 'medias';

    private function __construct(
        public ?string $uid,
        public ?int $id,
    ) {}

    public static function uid(string $uid): self
    {
        if (! Uid::isValid($uid)) {
            throw InvalidReference::for($uid);
        }

        return new self($uid, null);
    }

    /**
     * A 1.x reference, by internal id.
     */
    public static function legacy(int $id): self
    {
        if ($id < 1) {
            throw InvalidReference::for((string) $id);
        }

        return new self(null, $id);
    }

    public static function forMedia(Media $media): self
    {
        return self::uid($media->uid());
    }

    /**
     * @throws InvalidReference
     */
    public static function parse(string $value): self
    {
        return self::tryParse($value) ?? throw InvalidReference::for($value);
    }

    public static function tryParse(string $value): ?self
    {
        if (preg_match('#^'.self::SCHEME.'://([^/?\#\s]+)$#', $value, $matches) === 1) {
            return Uid::isValid($matches[1]) ? new self($matches[1], null) : null;
        }

        if (preg_match('#^'.self::LEGACY_SCHEME.'://([1-9][0-9]{0,18})$#', $value, $matches) === 1) {
            $id = filter_var($matches[1], FILTER_VALIDATE_INT);

            return is_int($id) ? new self(null, $id) : null;
        }

        return null;
    }

    public static function isReference(string $value): bool
    {
        return self::tryParse($value) !== null;
    }

    public function isLegacy(): bool
    {
        return $this->id !== null;
    }

    public function equals(self $other): bool
    {
        return $this->uid === $other->uid && $this->id === $other->id;
    }

    public function __toString(): string
    {
        return $this->uid !== null
            ? self::SCHEME.'://'.$this->uid
            : self::LEGACY_SCHEME.'://'.$this->id;
    }
}
