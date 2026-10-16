<?php

namespace Folklore\Mediatheque\Contracts\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Support\Collection;

/**
 * A media: one piece of content (a video, an audio file, an image, a
 * document, a font or a caption track) and its files.
 *
 * Applications set their own model in `mediatheque.models.media`. Package
 * code depends on this contract, never on a concrete model.
 */
interface Media
{
    /**
     * The public id, a prefixed ULID (`med_01JAB3…`). Set before the media
     * is saved, and never changed.
     */
    public function uid(): string;

    /**
     * The type: `video`, `audio`, `image`, `document`, `font`, `captions`,
     * or a custom type.
     */
    public function type(): string;

    public function name(): ?string;

    public function status(): MediaStatus;

    public function visibility(): Visibility;

    /**
     * The media's metadata, with the canonical keys (Metadata\MetadataKey),
     * or one of them.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function metadata(?string $key = null, mixed $default = null): mixed;

    /**
     * The ingested file. Null while a media imported from a URL waits for
     * its download.
     */
    public function original(): ?File;

    /**
     * A file by handle: `original`, a rendition's name. With an index, a file
     * of a group (`file('thumbnails', 2)`). Without one, a single file, or the
     * first file of a group.
     */
    public function file(string $handle, ?int $index = null): ?File;

    /**
     * Every file, or the files of one handle, ordered by index.
     *
     * @return Collection<int, File>
     */
    public function files(?string $handle = null): Collection;

    /**
     * The file with the `poster` role, or else the first file with the
     * `thumbnail` role, whatever the type.
     */
    public function thumbnail(): ?File;

    /**
     * The URL of a file: the main rendition by default, or the original
     * until it exists. A temporary URL when the media is private.
     */
    public function url(?string $handle = null): ?string;

    public function temporaryUrl(?string $handle, DateTimeInterface $expiration): ?string;
}
