<?php
namespace Folklore\Mediatheque\Contracts\Support;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\File;

interface HasFiles
{
    public function getFiles(): Collection;

    public function getFile(string $handle): ?File;

    public function hasFile(string $handle): bool;

    public function setOriginalFile($file, array $extraData = []): void;

    public function getOriginalFile(): ?File;

    public function setFile(string $handle, File $file): void;

    public function removeFile(string $handle): void;

    public function addFile(File $file, ?string $handle = null): void;
}
