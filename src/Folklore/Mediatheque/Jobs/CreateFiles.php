<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\File;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Jobs\ExecFileCreator;
use Exception;

class CreateFiles implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $model;
    public $onlyMissingFiles;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(HasFilesInterface $model, $onlyMissingFiles = false)
    {
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
        $filesCreators = $this->model->getFilesCreators();

        if (!is_null($filesCreators) && sizeof($filesCreators)) {
            foreach ($filesCreators as $handle => $fileCreator) {
                if (is_string($fileCreator)) {
                    $fileCreator = app($fileCreator);
                }

                $job = new ExecFileCreator($fileCreator, $handle, $this->model, $this->onlyMissingFiles);
                if ($this->model->shouldQueueFileCreator($handle, $fileCreator)) {
                    app(Dispatcher::class)->dispatch($job);
                } else {
                    app(Dispatcher::class)->dispatchNow($job);
                }
            }
        }
    }
}
