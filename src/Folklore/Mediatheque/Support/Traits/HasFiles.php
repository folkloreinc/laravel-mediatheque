<?php namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Contracts\Bus\Dispatcher;
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

    public function setOriginalFile($file, $extraData = [])
    {
        if (is_string($file)) {
            $file = new File($file);
        }

        // Gather data about the media
        $name =
            $file instanceof UploadedFile
                ? $file->getClientOriginalName()
                : $file->getFilename();
        $path = $file->getRealPath();
        $metadata = app(MetadataService::class)->getMetadata($path);
        $data = array_merge(
            [
                'name' => $name,
                'type' => $this->type
            ],
            $extraData
        );
        if (!isset($data['type'])) {
            $data['type'] = app(TypeFactory::class)->typeFromPath($path);
        }

        // Build original file model
        $originalFile = app(FileContract::class);
        $originalFile
            ->setFile(
                $file,
                array_merge($data, [
                    'handle' => 'original',
                    'metadata' => $metadata
                ])
            )
            ->save();

        // Save the media model
        $this->fill($data)
            ->setMetadata($metadata)
            ->setFile('original', $originalFile)
            ->save();

        return $this;
    }

    /**
     * Get the original file
     * @return \Folklore\Mediatheque\Contracts\Model\File
     */
    public function getOriginalFile()
    {
        $this->loadMissing('files');
        return $this->files->original;
    }

    /**
     * Set the file for a specific handle
     * @param string $handle The handle
     * @param \Folklore\Mediatheque\Contracts\Model\File $file The file to set
     * @return $this
     */
    public function setFile($handle, $file)
    {
        $this->loadMissing('files');
        $currentFile = $this->files->{$handle};
        if ($currentFile) {
            $this->removeFile($currentFile);
        }
        if (!is_null($file)) {
            $this->addFile($file, $handle);
        }
        return $this;
    }

    /**
     * Remove a file from the files relationship
     * @param  string|\Folklore\Mediatheque\Contracts\Model\File $handle The handle or tthe file to remove
     * @return $this
     */
    public function removeFile($handle)
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
        return $this;
    }

    /**
     * Add a file to the files relationship
     * @param \Folklore\Mediatheque\Contracts\Model\File $file The file model
     * @param string $handle The handle of the file
     * @return $this
     */
    public function addFile($file, $handle = null)
    {
        $this->files()->attach($file, [
            'handle' => $handle
        ]);
        $this->load('files');
        $eventClass = config('mediatheque.events.file_attached');
        event(new $eventClass($this, $file));
        return $this;
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

    /**
     *
     * Sync methods
     *
     */
    public function syncFiles($items = array())
    {
        $model = get_class(app(FileContract::class));
        return $this->syncMorph($model, 'morphable', 'files', $items);
    }

    /**
     *
     * Accessors and mutators
     *
     */
    protected function getOriginalFileAttribute()
    {
        return $this->getOriginalFile();
    }
}
