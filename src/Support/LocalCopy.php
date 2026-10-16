<?php

namespace Folklore\Mediatheque\Support;

/**
 * A local path of a file on a disk. A temporary copy is removed by
 * cleanup(); a file of a local disk is left where it is.
 */
final class LocalCopy
{
    public function __construct(
        public readonly string $path,
        public readonly bool $temporary,
    ) {}

    public function cleanup(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
