<?php

namespace Folklore\Mediatheque\Ingest;

use Folklore\Mediatheque\Support\TypeDefinition;

/**
 * A file that ingest accepted, before it is stored: its type and mime,
 * detected from its content, its size, and the local file to store.
 *
 * A file on a disk is not read: `$local` holds its first bytes only, it is
 * copied from its disk, and its checksum is computed by its probe
 * (docs/decisions/0015). A sanitized SVG is stored from `$local`.
 */
final readonly class IncomingFile
{
    public function __construct(
        public Source $source,
        public TypeDefinition $type,
        public string $mime,
        public int $size,
        public string $local,
        public bool $sanitized = false,
    ) {}

    /**
     * The extension it is stored with, derived from its mime.
     */
    public function extension(): string
    {
        return ContentType::extension($this->mime);
    }

    /**
     * Whether it is stored from `$local`, rather than copied from its disk.
     */
    public function readsLocally(): bool
    {
        return $this->source->kind !== SourceKind::Disk || $this->sanitized;
    }

    /**
     * Its SHA-256, when it is stored from `$local`; null for a file copied
     * from a disk.
     */
    public function checksum(): ?string
    {
        return $this->readsLocally() ? (string) hash_file('sha256', $this->local) : null;
    }
}
