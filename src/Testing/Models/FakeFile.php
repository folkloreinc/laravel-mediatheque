<?php

namespace Folklore\Mediatheque\Testing\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Support\Arr;

/**
 * An in-memory file of the fake.
 */
class FakeFile implements File
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public FakeMedia $media,
        public UrlGenerator $urls,
        public string $handle,
        public ?string $source,
        public ?string $path,
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
        public ?string $definitionHash = null,
        public ?string $provider = null,
        public ?string $name = null,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function role(): ?string
    {
        return $this->role;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function mime(): ?string
    {
        return $this->mime;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    public function checksum(): ?string
    {
        return $this->checksum;
    }

    public function width(): ?int
    {
        return $this->width;
    }

    public function height(): ?int
    {
        return $this->height;
    }

    public function duration(): ?float
    {
        return $this->duration;
    }

    public function bitrate(): ?int
    {
        return $this->bitrate;
    }

    public function partsCount(): ?int
    {
        return $this->partsCount;
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->metadata : Arr::get($this->metadata, $key, $default);
    }

    public function visibility(): Visibility
    {
        return $this->media->visibility;
    }

    public function isRemote(): bool
    {
        return $this->provider !== null;
    }

    public function url(): ?string
    {
        return $this->urls->url($this);
    }

    public function temporaryUrl(DateTimeInterface $expiration, bool|string $download = false): ?string
    {
        return $this->urls->temporaryUrl($this, $expiration, $download);
    }
}
