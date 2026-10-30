<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

/**
 * A file an operation wrote, in its output directory, and what the operation
 * reports about it, which goes to the rendition's metadata.
 */
final readonly class LocalOutput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $path,
        public string $mime,
        public ?int $index = null,
        public array $metadata = [],
    ) {}
}
