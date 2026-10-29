<?php

namespace Folklore\Mediatheque\Compat\Concerns;

use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Compat\MetadataValue;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Upgrade\LegacyHandle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use SplFileInfo;

/**
 * The methods of the 1.x media model, kept until 3.0 as proxies of the 2.0
 * API (docs/decisions/0002, docs/upgrade.md). Each one reports its use on
 * the `deprecations` channel.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Media
 */
trait HasV1MediaMethods
{
    /**
     * @deprecated Use file($handle, $index): `getFile('thumbnails.0')` is `file('thumbnails', 0)`.
     */
    public function getFile(string $handle): ?File
    {
        Deprecation::trigger('Media::getFile() is deprecated: use file($handle, $index).');
        $legacy = LegacyHandle::parse($handle);

        return $this->file($legacy->handle, $legacy->index) ?? ($legacy->index !== null ? $this->file($handle) : null);
    }

    /**
     * @deprecated Use file($handle, $index) !== null.
     */
    public function hasFile(string $handle): bool
    {
        Deprecation::trigger('Media::hasFile() is deprecated: use file($handle, $index) !== null.');
        $legacy = LegacyHandle::parse($handle);

        return ($this->file($legacy->handle, $legacy->index) ?? ($legacy->index !== null ? $this->file($handle) : null)) !== null;
    }

    /**
     * The files keyed by their 1.x handle: `thumbnails.0` for a file of a
     * group.
     *
     * @deprecated Use files().
     *
     * @return Collection<string, File>
     */
    public function getFiles(): Collection
    {
        Deprecation::trigger('Media::getFiles() is deprecated: use files().');

        $files = [];
        foreach ($this->files() as $file) {
            $files[LegacyHandle::format($file->handle(), $file->index())] = $file;
        }

        return new Collection($files);
    }

    /**
     * @deprecated Use original().
     */
    public function getOriginalFile(): ?File
    {
        Deprecation::trigger('Media::getOriginalFile() is deprecated: use original().');

        return $this->original();
    }

    /**
     * @deprecated Use files() and their role(), or thumbnail().
     *
     * @return Collection<int, File>
     */
    public function getThumbnails(): Collection
    {
        Deprecation::trigger('Media::getThumbnails() is deprecated: use thumbnail(), or the files() whose role() is "thumbnail".');

        return $this->files()->filter(fn (File $file) => $file->role() === 'thumbnail')->values();
    }

    /**
     * `$media->files`, the 1.x relation, reads the media's files: 2.0's
     * files() is a method of the contract, not a relation.
     *
     * @deprecated Use files().
     *
     * @return EloquentCollection<int, Model&File>
     */
    public function getFilesAttribute(): EloquentCollection
    {
        Deprecation::trigger('Media::$files is deprecated: use files().');

        $files = $this->getRelationValue('mediaFiles');

        return $files instanceof EloquentCollection ? $files : new EloquentCollection;
    }

    /**
     * The metadata by name, as values with getValue(). The 1.x names of
     * renamed keys are there too: `font_family_name`, `colors`.
     *
     * @deprecated Use metadata($key).
     *
     * @return Collection<string, MetadataValue>
     */
    public function getMetadatas(): Collection
    {
        Deprecation::trigger('Media::getMetadatas() is deprecated: use metadata($key).');

        return $this->v1Metadata();
    }

    /**
     * @deprecated Use metadata($key).
     */
    public function getMetadata(string $name): ?MetadataValue
    {
        Deprecation::trigger('Media::getMetadata() is deprecated: use metadata($key).');

        return $this->v1Metadata()->get($name);
    }

    /**
     * @return Collection<string, MetadataValue>
     */
    private function v1Metadata(): Collection
    {
        $values = [];
        foreach ($this->metadata() as $name => $value) {
            $values[(string) $name] = new MetadataValue((string) $name, $value);
        }

        $fonts = $this->metadata('font_names');
        if (is_array($fonts) && isset($fonts[0])) {
            $values['font_family_name'] ??= new MetadataValue('font_family_name', $fonts[0]);
        }
        if ($this->metadata('palette') !== null) {
            $values['colors'] ??= new MetadataValue('colors', $this->metadata('palette'));
        }

        return new Collection($values);
    }

    /**
     * @deprecated Use url().
     */
    public function getUrl(): ?string
    {
        Deprecation::trigger('Media::getUrl() is deprecated: use url().');

        return $this->url();
    }

    /**
     * Ingest a file as this media's original: a new media when this one
     * isn't saved, its original replaced otherwise. Only the `name` of
     * `$extraData` is read; the type comes from the content.
     *
     * @deprecated Use Mediatheque::ingest($file), or $media->replaceOriginal($file).
     *
     * @param  UploadedFile|SplFileInfo|string  $file
     * @param  array<string, mixed>  $extraData
     *
     * @throws FileRejected
     */
    public function setOriginalFile(mixed $file, array $extraData = []): void
    {
        Deprecation::trigger('Media::setOriginalFile() is deprecated: use Mediatheque::ingest($file), or $media->replaceOriginal($file).');

        $source = $file instanceof SplFileInfo && ! $file instanceof UploadedFile ? $file->getPathname() : $file;
        $name = is_string($extraData['name'] ?? null) ? $extraData['name'] : null;
        $mediatheque = app(Mediatheque::class);

        if ($this->exists) {
            $mediatheque->replaceOriginal($this, $source, new IngestOptions(name: $name));

            return;
        }

        $media = $mediatheque->ingest($source, name: $name);
        if ($media instanceof Model) {
            $this->setRawAttributes($media->getAttributes(), true);
            $this->setRelations($media->getRelations());
            $this->exists = true;
            $this->wasRecentlyCreated = true;
        }
    }

    /**
     * @deprecated Use Mediatheque::runPipeline($media, $name).
     */
    public function runPipeline(string $name): ?PipelineRun
    {
        Deprecation::trigger('Media::runPipeline() is deprecated: use Mediatheque::runPipeline($media, $name).');

        return app(Mediatheque::class)->runPipeline($this, $name);
    }
}
