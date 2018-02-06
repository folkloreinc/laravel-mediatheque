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

class RunPipelineJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $pipeline;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Pipeline $pipeline, FileContract $model)
    {
        $this->pipeline = $pipeline;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

    }
}
