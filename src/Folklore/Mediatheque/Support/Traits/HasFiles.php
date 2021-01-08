<?php
namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Contracts\Services\Metadata as MetadataService;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait HasFiles
{
    public static function bootHasFiles()
    {
        $observer = config('mediatheque.observers.has_files', null);
        if (!is_null($observer)) {
            static::observe($observer);
        }
    }

    /**
     *
     * Relationships
     *
     */
    public function files()
    {
        $morphName = 'morphable';
        $key = 'file_id';
        $model = app(FileContract::class);
        $modelClass = get_class($model);
        $table = $model->getTable() . '_pivot';
        $query = $this->morphToMany($modelClass, $morphName, $table, null, $key)
            ->withTimestamps()
            ->withPivot('handle', 'order')
            ->orderBy('order', 'asc');
        return $query;
    }

    public function getFiles(): Collection
    {
        return $this->files->mapWithKeys(function ($file) {
            return [
                $file->getHandle() => $file,
            ];
        });
    }

    public function getFile(string $handle): ?FileContract
    {
        return $this->getFiles()->get($handle);
    }

    public function hasFile(string $handle): bool
    {
        return $this->getFiles()->has($handle);
    }

    public function setOriginalFile($file, array $extraData = []): void
    {
        if (is_string($file)) {
            $file = new File($file);
        }

        // Gather data about the media
        $name =
            $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
        $path = $file->getRealPath();
        $metadata = app(MetadataService::class)->getMetadata($path);
        $data = array_merge(
            [
                'name' => $name,
                'type' => $this->type,
            ],
            $extraData
        );
        if (!isset($data['type'])) {
            $data['type'] = app(TypeFactory::class)->typeFromPath($path);
        }

        // Build original file model
        $originalFile = app(FileContract::class);
        $originalFile->setFile(
            $file,
            array_merge($data, [
                'handle' => 'original',
                'metadata' => $metadata,
            ])
        );
        $originalFile->save();

        // Save the media model
        $this->fill($data);
        $this->setMetadatas($metadata);
        $this->setFile('original', $originalFile);
        $this->save();
    }

    /**
     * Get the original file
     * @return \Folklore\Mediatheque\Contracts\Model\File
     */
    public function getOriginalFile(): ?FileContract
    {
        $this->loadMissing('files');
        return $this->files->get('original');
    }

    /**
     * Set the file for a specific handle
     * @param string $handle The handle
     * @param \Folklore\Mediatheque\Contracts\Model\File $file The file to set
     * @return $this
     */
    public function setFile(string $handle, FileContract $file): void
    {
        $this->loadMissing('files');
        $currentFile = $this->getFile($handle);
        if ($currentFile) {
            $this->removeFile($currentFile);
        }
        if (!is_null($file)) {
            $this->addFile($file, $handle);
        }
    }

    /**
     * Remove a file from the files relationship
     * @param  string|\Folklore\Mediatheque\Contracts\Model\File $handle The handle or tthe file to remove
     * @return $this
     */
    public function removeFile(string $handle): void
    {
        $table = app(FileContract::class)->getTable() . '_pivot';
        $file =
            $handle instanceof FileContract
                ? $handle
                : $this->files()
                    ->where($table . '.handle', $handle)
                    ->first();
        if ($file) {
            $this->files()->detach($file);
            $this->load('files');
            $eventClass = config('mediatheque.events.file_detached');
            event(new $eventClass($this, $file));
        }
    }

    /**
     * Add a file to the files relationship
     * @param \Folklore\Mediatheque\Contracts\Model\File $file The file model
     * @param string $handle The handle of the file
     * @return $this
     */
    public function addFile(FileContract $file, ?string $handle = null): void
    {
        $this->files()->attach($file, [
            'handle' => !is_null($handle) ? $handle : $file->getHandle(),
        ]);
        $this->load('files');
        $eventClass = config('mediatheque.events.file_attached');
        event(new $eventClass($this, $file));
    }
}
