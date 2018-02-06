<?php namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Contracts\Bus\Dispatcher;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\FilePivot as FilePivotContract;
use Folklore\Mediatheque\Contracts\MetadataGetter;
use Folklore\Mediatheque\Jobs\CreateFiles as CreateFilesJob;
use Folklore\Mediatheque\Models\Observers\HasFilesObserver;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait HasFiles
{
    public static function bootHasFiles()
    {
        static::observe(HasFilesObserver::class);
    }

    public function getFilesCreators()
    {
        $filesCreators = [];
        if (method_exists($this, 'filesCreators')) {
            $filesCreators = $this->filesCreators();
        } elseif (isset($this->filesCreators)) { // @TODO check $filesCreator property vs model magic method
            $filesCreators = $this->filesCreators;
        }
        return $filesCreators;
    }

    public function shouldQueueFileCreator($handle, $fileCreator)
    {
        return config('mediatheque.file_creators_use_queue');
    }

    public function setOriginalFile($file, $data = [])
    {
        if (is_string($file)) {
            $file = new File($file);
        }

        $currentOriginalFile = $this->files->original;

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

        if ($currentOriginalFile) {
            $this->files()->detach($currentOriginalFile);
        }

        $this->files()->attach($originalFile, [
            'handle' => 'original',
            'order' => 0
        ]);

        app(Dispatcher::class)->dispatchNow(new CreateFilesJob($this));
    }

    public function getOriginalFile()
    {
        return $this->files->original;
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
        $pivot = app(FilePivotContract::class);
        $pivotClass = get_class($pivot);
        $table = $model->getTable().'_pivot';
        $query = $this->morphToMany($modelClass, $morphName, $table, null, $key)
                        ->withTimestamps()
                        ->withPivot('handle', 'order')
                        ->orderBy('order', 'asc')
                        ->using($pivotClass);
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
