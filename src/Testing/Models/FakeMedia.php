<?php

namespace Folklore\Mediatheque\Testing\Models;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * An in-memory media of the fake. Its state is public, so tests and the fake
 * can read and change it directly.
 */
class FakeMedia implements Media
{
    /** @var list<FakeFile> */
    public array $files = [];

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $id,
        public string $uid,
        public string $type,
        public ?string $name,
        public MediaStatus $status = MediaStatus::Pending,
        public Visibility $visibility = Visibility::Public,
        public array $metadata = [],
        public array $data = [],
        public ?string $mainRendition = null,
    ) {}

    public function uid(): string
    {
        return $this->uid;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function status(): MediaStatus
    {
        return $this->status;
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    public function metadata(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->metadata : Arr::get($this->metadata, $key, $default);
    }

    public function original(): ?File
    {
        return $this->file(File::ORIGINAL);
    }

    public function file(string $handle, ?int $index = null): ?File
    {
        return $this->files($handle)
            ->first(fn (File $file) => $index === null || $file->index() === $index);
    }

    public function files(?string $handle = null): Collection
    {
        $files = array_filter($this->files, fn (FakeFile $file) => $handle === null || $file->handle === $handle);
        usort($files, fn (FakeFile $a, FakeFile $b) => [$a->handle, $a->index ?? 0] <=> [$b->handle, $b->index ?? 0]);

        return self::collect($files);
    }

    public function thumbnail(): ?File
    {
        $files = $this->files();

        return $files->first(fn (File $file) => $file->role() === 'poster')
            ?? $files->first(fn (File $file) => $file->role() === 'thumbnail');
    }

    public function url(?string $handle = null): ?string
    {
        return $this->playedFile($handle)?->url();
    }

    public function temporaryUrl(?string $handle, DateTimeInterface $expiration): ?string
    {
        return $this->playedFile($handle)?->temporaryUrl($expiration);
    }

    /**
     * Attach a file, replacing the file with the same handle and index.
     */
    public function attach(FakeFile $file): void
    {
        $this->files = array_values(array_filter(
            $this->files,
            fn (FakeFile $existing) => $existing->handle !== $file->handle || $existing->index !== $file->index,
        ));
        $this->files[] = $file;
    }

    /**
     * @param  list<File>  $files
     * @return Collection<int, File>
     */
    private static function collect(array $files): Collection
    {
        return new Collection($files);
    }

    private function playedFile(?string $handle): ?File
    {
        if ($handle !== null) {
            return $this->file($handle);
        }

        return ($this->mainRendition !== null ? $this->file($this->mainRendition) : null) ?? $this->original();
    }
}
