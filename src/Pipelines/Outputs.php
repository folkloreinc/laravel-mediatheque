<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Metadata\MetadataKey;
use InvalidArgumentException;

/**
 * What a completed rendition produced: files, metadata outputs, or both
 * (docs/decisions/0011). A rendition without files writes its metadata to
 * the media; a rendition with files writes it to its main file (index 0 for
 * a group).
 */
final readonly class Outputs
{
    /** @var list<OutputFile> */
    public array $files;

    /** @var array<string, mixed> */
    public array $metadata;

    /**
     * @param  list<OutputFile>  $files
     * @param  array<array-key, mixed>  $metadata  Normalized to the canonical keys
     */
    public function __construct(array $files = [], array $metadata = [])
    {
        if (count($files) > 1) {
            $indexes = array_map(fn (OutputFile $file) => $file->index, $files);
            if (in_array(null, $indexes, true) || count(array_unique($indexes)) !== count($indexes)) {
                throw new InvalidArgumentException('The files of a group each need their own index.');
            }
            usort($files, fn (OutputFile $a, OutputFile $b) => $a->index <=> $b->index);
        }

        $this->files = $files;
        $this->metadata = MetadataKey::normalize($metadata);
    }

    /**
     * A rendition with no output: `keep: false`, or a driver that reports
     * nothing more.
     */
    public static function none(): self
    {
        return new self;
    }

    public static function file(OutputFile $file): self
    {
        return new self([$file]);
    }

    /**
     * @param  list<OutputFile>  $files
     */
    public static function group(array $files): self
    {
        return new self($files);
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->files, [...$this->metadata, ...MetadataKey::normalize($metadata)]);
    }

    public function hasFiles(): bool
    {
        return $this->files !== [];
    }

    /**
     * The file that gets the metadata outputs: the single file, or index 0
     * of a group.
     */
    public function mainFile(): ?OutputFile
    {
        return $this->files[0] ?? null;
    }
}
