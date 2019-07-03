<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Bus\Dispatcher;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Contracts\Models\Pipeline;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Carbon\Carbon;
use Exception;

class RunPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        $this->pipeline->markStarted();

        $this->model->load('files');

        $definition = $this->pipeline->definition;
        $jobs = $definition->getJobs();
        foreach ($jobs as $name => $job) {
            // Ensure job definition is an array and merge handle
            $job = array_merge(
                is_string($job) ? ['job' => $job] : $job,
                ['name' => $name]
            );

            if (!isset($job['from_file']) || is_null($job['from_file'])) {
                $job['from_file'] = $definition->from_file;
            }

            if (!isset($job['queue']) || is_null($job['queue'])) {
                $job['queue'] = $definition->queue;
            }

            $jobModel = $this->pipeline->jobs()
                ->where('name', $name)
                ->first();
            if (!$jobModel) {
                // Create the pipeline job model
                $jobModel = app(PipelineJob::class);
                $jobModel->name = $name;
                $jobModel->pipeline_id = $this->pipeline->id;
                $jobModel->definition = $job;
                $jobModel->save();
            }

            // Run the job
            if ($jobModel->canRun($this->model)) {
                $jobModel->run();
            }
        }
    }

    /**
     * The job failed to process.
     *
     * @param  Exception  $exception
     * @return void
     */
    public function failed(Exception $exception = null)
    {
        $this->pipeline->markFailed($exception);
    }
}
