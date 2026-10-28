<?php

namespace Folklore\Mediatheque\Contracts\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\Processing;
use Folklore\Mediatheque\Support\MediaUsage;
use Folklore\Mediatheque\Transcoders\TranscoderError;
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
     * The non-destructive edits of the media's image, as Panneau 5 stores
     * them (`MediaTransform`: crop, rotate, flipX, flipY and focalPoint), or
     * null when it has none (docs/decisions/0022). The crop and the focal
     * point are relative to the upright original.
     *
     * @return array<string, mixed>|null
     */
    public function transform(): ?array;

    /**
     * Its run in progress, the latest one: the media's status, the run's
     * uid, pipeline and progress, and the rendition that is running. Null
     * when no run is in progress (docs/decisions/0023).
     */
    public function processing(): ?Processing;

    /**
     * Why a `failed` media failed: the error of its latest run that ended
     * without completing, its probe's included. Null when the media is not
     * `failed`, or when that run was canceled and has no error.
     */
    public function failure(): ?TranscoderError;

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

    /**
     * A temporary URL of the same file as url(), public media included.
     *
     * @param  bool|string  $download  `true` to download the file under the
     *                                 media's name with the file's extension (`episode-1.mp4`), or under
     *                                 the file's name when the media has none; a file name to download
     *                                 it under that name; or `false` to show it (UrlGenerator)
     */
    public function temporaryUrl(?string $handle, DateTimeInterface $expiration, bool|string $download = false): ?string;

    /**
     * The models that use the media: one usage per model and handle it is
     * attached under, through `HasMedias` (docs/decisions/0020).
     *
     * @return Collection<int, MediaUsage>
     */
    public function usages(): Collection;

    /**
     * Whether a model uses the media. A media in use can't be deleted unless
     * the deletion is forced.
     */
    public function isUsed(): bool;
}
