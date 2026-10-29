<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Pipelines\Rendition;

/**
 * The file handles of 1.x. A rendition with several files gave each one the
 * handle of the rendition and its index, `thumbnails.0` to `thumbnails.4`;
 * 2.0 keeps them apart, the handle `thumbnails` and the index 0
 * (docs/decisions/0004).
 */
final readonly class LegacyHandle
{
    private function __construct(
        public string $handle,
        public ?int $index,
    ) {}

    /**
     * `thumbnails.2` is the handle `thumbnails` at index 2; any other handle
     * has no index.
     */
    public static function parse(string $legacy): self
    {
        if (preg_match('/^(.+)\.(\d{1,9})$/', $legacy, $matches) === 1) {
            return new self($matches[1], (int) $matches[2]);
        }

        return new self($legacy, null);
    }

    /**
     * The 1.x handle of a file: `thumbnails.2` for the file at index 2.
     */
    public static function format(string $handle, ?int $index): string
    {
        return $index === null ? $handle : $handle.'.'.$index;
    }

    /**
     * Whether the handle follows the 2.0 rule: a letter first, then letters,
     * digits, `_` and `-`, 64 characters at most (docs/decisions/0011).
     */
    public function isValid(): bool
    {
        return $this->handle === File::ORIGINAL || Rendition::isValidHandle($this->handle);
    }

    /**
     * The role of a file of 1.x, from its handle: 1.x had no roles, and its
     * thumbnails (video frames, audio waveforms, document pages) all had a
     * handle starting with `thumbnail`.
     */
    public function role(): string
    {
        return match (true) {
            $this->handle === File::ORIGINAL => File::ORIGINAL,
            str_starts_with($this->handle, 'thumbnail'), $this->handle === 'waveform' => 'thumbnail',
            $this->handle === 'poster' => 'poster',
            default => Rendition::DEFAULT_ROLE,
        };
    }
}
