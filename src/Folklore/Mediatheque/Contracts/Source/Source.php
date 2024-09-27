<?php

namespace Folklore\Mediatheque\Contracts\Source;

interface Source
{
    public function exists(string $path): bool;

    public function putFromContents(string $path, $contents);

    public function putFromLocalPath(string $path, string $localPath);

    public function delete(string $path);

    public function deleteDirectory(string $path);

    public function move(string $source, string $destination);

    public function copy(string $source, string $destination);

    public function copyToLocalPath(string $path, string $localPath);

    public function getUrl(string $path): string;
}
