<?php

namespace Folklore\Mediatheque\Pipelines;

use InvalidArgumentException;

/**
 * A file a transcoder produced, as it reports it to the recorder. The path
 * is where the driver wrote it; the file records it as it is.
 */
final readonly class OutputFile
{
    /**
     * @param  string|null  $source  The run's output source when null
     * @param  string|null  $role  The rendition's role when null
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $path,
        public ?string $source = null,
        public ?int $index = null,
        public ?string $role = null,
        public ?string $mime = null,
        public ?int $size = null,
        public ?string $checksum = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?float $duration = null,
        public ?int $bitrate = null,
        public ?int $partsCount = null,
        public array $metadata = [],
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('An output file needs the path the driver wrote it to.');
        }
        foreach (['index' => $index, 'size' => $size, 'width' => $width, 'height' => $height, 'bitrate' => $bitrate, 'partsCount' => $partsCount] as $name => $value) {
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException(sprintf('An output file can\'t have a negative %s.', $name));
            }
        }
        if ($duration !== null && $duration < 0) {
            throw new InvalidArgumentException('An output file can\'t have a negative duration.');
        }
    }

    /**
     * Whether the driver gave the dimensions and duration of the file. A
     * file without them is probed for the missing fields
     * (docs/decisions/0011): `$needsDuration` for audio and video.
     */
    public function isComplete(bool $needsDuration = false): bool
    {
        return $this->width !== null && $this->height !== null
            && (! $needsDuration || ($this->duration !== null && $this->bitrate !== null));
    }
}
