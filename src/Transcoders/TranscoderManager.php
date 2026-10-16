<?php

namespace Folklore\Mediatheque\Transcoders;

use Closure;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The transcoders pipelines name in their `transcoder` key. The package
 * ships `ffmpeg`; other drivers register with extend() (`laravel-eclair`
 * registers `eclair`).
 */
class TranscoderManager
{
    /** @var array<string, Closure(Container): Transcoder> */
    private array $creators = [];

    /** @var array<string, Transcoder> */
    private array $drivers = [];

    public function __construct(
        private readonly Container $container,
    ) {
        $this->creators['ffmpeg'] = fn (Container $container) => $container->make(FfmpegTranscoder::class);
    }

    /**
     * @param  Closure(Container): Transcoder  $creator
     */
    public function extend(string $name, Closure $creator): void
    {
        $this->creators[$name] = $creator;
        unset($this->drivers[$name]);
    }

    public function driver(string $name): Transcoder
    {
        if (! isset($this->creators[$name])) {
            throw new InvalidArgumentException(sprintf('The transcoder "%s" is not registered.', $name));
        }

        return $this->drivers[$name] ??= ($this->creators[$name])($this->container);
    }
}
