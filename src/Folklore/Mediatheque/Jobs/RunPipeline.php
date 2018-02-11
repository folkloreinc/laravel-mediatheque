<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Contracts\Models\Pipeline;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Carbon\Carbon;

class RunPipeline implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $model;
    public $pipeline;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(HasFilesInterface $model, Pipeline $pipeline = null)
    {
        $this->model = $model;
        $this->pipeline = $pipeline;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->pipeline->started = true;
        $this->pipeline->started_at = Carbon::now();
        $this->pipeline->save();

        $this->model->load('files');

        $definition = $this->pipeline->definition;
        $jobs = $definition->getJobs();
        foreach ($jobs as $handle => $job) {
            // Ensure job definition is an array and merge handle
            $job = array_merge([
                'from_file' => $definition->from_file,
                'queue' => $definition->queue,
            ], is_string($job) ? [
                'job' => $job,
            ] : $job, [
                'handle' => $handle
            ]);

            // Create the pipeline job model
            $jobModel = app(PipelineJob::class);
            $jobModel->name = $handle;
            $jobModel->pipeline_id = $this->pipeline->id;
            $jobModel->definition = $job;
            $jobModel->save();

            // Run the job
            $fromFile = array_get($job, 'from_file');
            $file = $this->model->files->{$fromFile};
            if ($file) {
                $jobModel->run();
            }
        }
    }
}
