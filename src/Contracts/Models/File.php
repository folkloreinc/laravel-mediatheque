<?php

namespace Folklore\Mediatheque\Contracts\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Enums\Visibility;

/**
 * A file of a media: its original, or a file of one of its renditions.
 */
interface File
{
    public const string ORIGINAL = 'original';

    /**
     * `original`, or the name of the rendition that produced it.
     */
    public function handle(): string;

    /**
     * The position in a group (thumbnails, document pages), from 0. Null for
     * a single file.
     */
    public function index(): ?int;

    /**
     * `main`, `thumbnail`, `poster`, `captions`, `playlist`…
     */
    public function role(): ?string;

    /**
     * The storage source. Null for a remote file.
     */
    public function source(): ?string;

    /**
     * The path on its source, as recorded when the file was stored: never
     * rebuilt. For a multi-part rendition (HLS), its main file.
     */
    public function path(): ?string;

    public function mime(): ?string;

    /**
     * In bytes.
     */
    public function size(): ?int;

    /**
     * SHA-256, in hex.
     */
    public function checksum(): ?string;

    public function width(): ?int;

    public function height(): ?int;

    /**
     * In seconds.
     */
    public function duration(): ?float;

    /**
     * In bits per second.
     */
    public function bitrate(): ?int;

    /**
     * How many files sit next to the main one (HLS segments).
     */
    public function partsCount(): ?int;

    /**
     * Metadata outputs of the rendition (palette, scenes…), or one of them.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function metadata(?string $key = null, mixed $default = null): mixed;

    /**
     * The visibility of its media, which its file on the source follows.
     */
    public function visibility(): Visibility;

    /**
     * A file kept by a provider (a hosted video service) instead of a source.
     */
    public function isRemote(): bool;

    public function url(): ?string;

    public function temporaryUrl(DateTimeInterface $expiration): ?string;
}
