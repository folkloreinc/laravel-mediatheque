<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Closure;
use Folklore\Mediatheque\Pipelines\Rendition;

/**
 * What an operation receives: its input, where to write, the rendition, the
 * probed metadata of the media, and a callback for its progress.
 */
final readonly class OperationContext
{
    /**
     * @param  array<string, mixed>  $metadata  The media's metadata, with the canonical keys
     * @param  Closure(float): void  $progress  Between 0 and 1
     */
    public function __construct(
        public string $input,
        public string $outputDirectory,
        public Rendition $rendition,
        public array $metadata,
        public Closure $progress,
        public int $timeout,
    ) {}

    /**
     * An option of the rendition, or a default.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->rendition->options[$key] ?? $default;
    }

    public function duration(): ?float
    {
        $duration = $this->metadata['duration'] ?? null;

        return is_int($duration) || is_float($duration) ? (float) $duration : null;
    }
}
