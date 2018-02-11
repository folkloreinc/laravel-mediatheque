<?php namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Contracts\Bus\Dispatcher;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\MetadataGetter;
use Folklore\Mediatheque\Models\Observers\HasFilesObserver;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait HasFiles
{
    public static function bootHasFiles()
    {
        static::observe(HasFilesObserver::class);
    }

    public function setOriginalFile($file, $data = [])
    {
        if (is_string($file)) {
            $file = new File($file);
        }

        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : $file->getFilename();
        $metadata = app(MetadataGetter::class)->getMetadata($file);
        $modelData = array_merge([
            'name' => $name
        ], $metadata, $data);
        $fileData = array_merge([
            'metadata' => $metadata,
            'handle' => 'original'
        ], $modelData);

        $originalFile = app(FileContract::class);
        $originalFile->setFile($file, $fileData);
        $originalFile->save();

        if (sizeof($modelData) || $this->getKey() === null) {
            $this->fill($modelData);
            $this->save();
        }

        $this->setFile('original', $originalFile);
    }

    public function getOriginalFile()
    {
        return $this->files->original;
    }

    public function setFile($handle, $file)
    {
        $currentFile = $this->files->{$handle};
        if ($currentFile) {
            $this->removeFile($currentFile);
        }

        if (!is_null($file)) {
            $this->addFile($file, $handle);
        }
    }

    public function removeFile($handle)
    {
        $table = app(FileContract::class)->getTable().'_pivot';
        $file = $handle instanceof FileContract
            ? $handle
            : $this->files()
                ->where($table.'.handle', $handle)
                ->first();
        if ($file) {
            $this->files()->detach($file);
            $eventClass = config('mediatheque.config.events.file_detached');
            event(new $eventClass($this, $file));
        }
    }

    public function addFile($file, $handle = null)
    {
        $this->files()->attach($file, [
            'handle' => $handle
        ]);
        $eventClass = config('mediatheque.config.events.file_attached');
        event(new $eventClass($this, $file));
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
        $table = $model->getTable().'_pivot';
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
