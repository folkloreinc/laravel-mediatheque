<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Contracts\Source\Source;

interface File
{
    public function getHandle(): ?string;

    public function getSource(): Source;

    public function setFile($file, array $data = []): void;

    public function deleteFile(): void;

    public function moveFile(string $newPath): void;

    public function copyFile(string $destinationPath): void;

    public function downloadFile(string $localPath): void;
}
