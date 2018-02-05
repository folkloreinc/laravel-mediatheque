<?php namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Contracts\Bus\Dispatcher;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasDimension as HasDimensionInterface;
use Folklore\Mediatheque\Support\Interfaces\HasDuration as HasDurationInterface;
use Folklore\Mediatheque\Support\Interfaces\HasFamilyName as HasFamilyNameInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPages as HasPagesInterface;
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

        $currentSourceFile = $this->files->source;

        $modelData = array_merge([
            'name' => $file instanceof UploadedFile ?
                $file->getClientOriginalName() : $file->getFilename()
        ], $data);

        if ($this instanceof HasDurationInterface) {
            $duration = $this->getDurationFromFile($file);
            if ($duration) {
                $modelData['duration'] = $duration;
            }
        }

        if ($this instanceof HasFamilyNameInterface) {
            $familyName = $this->getFamilyNameFromFile($file);
            if ($familyName) {
                $modelData['family_name'] = $familyName;
            }
        }

        if ($this instanceof HasDimensionInterface) {
            $dimension = $this->getDimensionFromFile($file);
            if ($dimension) {
                $modelData['width'] = $dimension['width'];
                $modelData['height'] = $dimension['height'];
            }
        }

        if ($this instanceof HasPagesInterface) {
            $pages = $this->getPagesCountFromFile($file);
            if ($pages) {
                $modelData['pages'] = $pages;
            }
        }

        $sourceFile = app(FileContract::class);
        $sourceFile->setFile($file, $modelData);
        $sourceFile->save();

        if (sizeof($modelData) || $this->getKey() === null) {
            $this->fill($modelData);
            $this->save();
        }

        if ($currentSourceFile) {
            $this->files()->detach($currentSourceFile);
        }

        $this->files()->attach($sourceFile, [
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
