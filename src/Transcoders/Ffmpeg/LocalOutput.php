<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

/**
 * A file an operation wrote, in its output directory.
 */
final readonly class LocalOutput
{
    public function __construct(
        public string $path,
        public string $mime,
        public ?int $index = null,
    ) {}
}
