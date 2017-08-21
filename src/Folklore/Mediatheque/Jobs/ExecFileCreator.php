<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\File;
use Folklore\Mediatheque\Contracts\FilesCreator;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;

class ExecFileCreator implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $fileCreator;
    public $fileCreatorHandle;
    public $model;
    public $file;
    public $onlyMissingFiles;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(FilesCreator $fileCreator, $fileCreatorHandle, HasFilesInterface $model, $file, $onlyMissingFiles = false)
    {
        $this->fileCreator = $fileCreator;
        $this->fileCreatorHandle = $fileCreatorHandle;
        $this->model = $model;
        $this->file = $file;
        $this->onlyMissingFiles = $onlyMissingFiles;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $handle = $this->fileCreatorHandle;
        
        $file = new File($this->file);
        $createdFiles = null;

        if ($this->onlyMissingFiles) {
            $modelFilesHandles =
                $this->model->files()
                ->wherePivot('handle', '!=', 'original')
                ->get()
                ->transform(function ($item) {
                    return $item->pivot->handle;
                })
                ->all();

            $filesToCreate = $this->fileCreator->getKeysOfFilesToCreate($file);
            if (is_bool($filesToCreate)) {
                if (in_array($handle, $modelFilesHandles)) {
                    $filesToCreate = false;
                }
            } else {
                if (!is_numeric($handle)) {
                    $filesToCreate = array_map(function ($key) use ($handle) {
                        return $handle.':'.$key;
                    }, $filesToCreate);
                }
                foreach ($filesToCreate as $index => $key) {
                    if (in_array($key, $modelFilesHandles)) {
                        $filesToCreate[$index] = null;
                    }
                }
                $filesToCreate = array_map(function ($key) use ($handle) {
                    if (is_null($key)) {
                        return null;
                    }
                    if (!is_numeric($handle)) {
                        $key = str_replace($handle.':', '', $key);
                        if (is_numeric($key)) {
                            return (int)$key;
                        }
                    }
                    return $key;
                }, $filesToCreate);
            }

            Log::debug('onlyMissingFiles', [
                'media id' => $this->model->id,
                'type' => get_class($this->model),
                'creator' => get_class($this->fileCreator),
                'keys' => $filesToCreate
            ]);
            $createdFiles = $this->fileCreator->createFiles($file, $filesToCreate);
        } else {
            $createdFiles = $this->fileCreator->createFiles($file);
        }

        if (!is_null($createdFiles)) {
            if (is_array($createdFiles)) {
                $single = false;
            } else {
                $single = true;
                $createdFiles = [$createdFiles];
            }
            $i = 0;
            foreach ($createdFiles as $key => $createdFilePath) {
                $createdFile = app(FileContract::class);
                $createdFile->setFile($createdFilePath);
                $createdFile->save();
                $fileHandle = [];
                if (!is_numeric($handle)) {
                    $fileHandle[] = $handle;
                }
                if ($single === false) {
                    $fileHandle[] = $key;
                }
                $this->model->files()->attach($createdFile, sizeof($fileHandle) ? [
                    'handle' => implode(':', $fileHandle),
                    'order' => is_numeric($key) ? $key : $i
                ] : []);
                $i++;
            }
        }
    }
}
