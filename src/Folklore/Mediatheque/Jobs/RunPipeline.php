<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
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
    public function __construct(Pipeline $pipeline, HasFilesInterface $model)
    {
        $this->pipeline = $pipeline;
        $this->model = $model;
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

        $definition = $this->pipeline->getDefinition();
        $jobs = $definition->jobs();
        foreach ($jobs as $name => $job) {
            // Ensure job definition is an array and merge handle
            $job = array_merge(
                is_string($job) ? ['job' => $job] : $job,
                ['name' => $name]
            );

            if (!isset($job['from_file']) || is_null($job['from_file'])) {
                $job['from_file'] = $definition->fromFile();
            }

            if (!isset($job['should_queue']) || is_null($job['should_queue'])) {
                $job['should_queue'] = $definition->shouldQueue();
            }

            $jobModel = $this->pipeline->getJob($name);
            if (!$jobModel) {
                // Create the pipeline job model
                $jobModel = app(PipelineJob::class);
                $jobModel->setDefinition($job);
                $this->pipeline->addJob($jobModel);
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
    public function failed($exception = null)
    {
        dd($exception);
        $this->pipeline->markFailed($exception);
    }
}
