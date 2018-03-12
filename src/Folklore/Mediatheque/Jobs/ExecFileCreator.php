<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\File;
use Folklore\Mediatheque\Contracts\FilesCreator;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Exception;

class ExecFileCreator implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $fileCreator;
    public $fileCreatorHandle;
    public $model;
    public $onlyMissingFiles;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(FilesCreator $fileCreator, $fileCreatorHandle, HasFilesInterface $model, $onlyMissingFiles = false)
    {
        $this->fileCreator = $fileCreator;
        $this->fileCreatorHandle = $fileCreatorHandle;
        $this->model = $model;
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

        $this->model->load('files');
        $originalFile = $this->model->getOriginalFile();
        $originalFileExt = pathinfo($originalFile->path, PATHINFO_EXTENSION);
        $downloadPath = tempnam(sys_get_temp_dir(), 'CreateFilesJob').'.'.$originalFileExt;
        if (!$originalFile->downloadFile($downloadPath)) {
            throw new Exception('Could not download original file');
        }

        $file = new File($downloadPath);
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
                $fileHandle = [];
                if (!is_numeric($handle)) {
                    $fileHandle[] = $handle;
                }
                if ($single === false) {
                    $fileHandle[] = $key;
                }
                $createdFile = app(FileContract::class);
                if (sizeof($fileHandle)) {
                    $createdFile->handle = implode(':', $fileHandle);
                }
                $createdFile->setFile($createdFilePath);
                $createdFile->save();
                $this->model->files()->attach($createdFile, sizeof($fileHandle) ? [
                    'handle' => implode(':', $fileHandle),
                    'order' => is_numeric($key) ? $key : $i
                ] : []);
                $i++;
            }
        }

        unlink($downloadPath);

        $this->model->touch();
    }
}
