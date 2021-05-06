<?php

namespace Folklore\Mediatheque\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Support\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineModel;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobModel;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class RunPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $pipelineJob;
    public $pipeline;
    public $model;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(PipelineJobModel $job, HasPipelinesInterface $model)
    {
        $this->pipelineJob = $job;
        $this->pipeline = $job->pipeline;
        $this->model = $model;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(Log $log)
    {
        $pipelineJob = $this->pipelineJob;
        $definition = $pipelineJob->getDefinition();
        $name = $pipelineJob->getName();
        $pipelineDefintion = $this->pipeline->getDefinition();
        $jobClass = $definition['job'];
        $fromFile = data_get($definition, 'from_file', $pipelineDefintion->fromFile());

        // Check if the file exists
        $file = $this->model->getFile($fromFile);
        if (!$file) {
            throw new Exception('File "'.$fromFile.'" is not available.');
        }

        $pipelineJob->markStarted();

        // Run the job
        $options = Arr::except($definition, ['from_file', 'job']);
        $newFile = $jobClass::dispatchNow($file, $options, $this->model);

        // Add files generated by the job to the model
        $isIndexed = is_array($newFile);
        $files = !is_array($newFile) ? [$newFile] : $newFile;
        foreach ($files as $index => $file) {
            $fileHandle = $isIndexed && !is_null($name) ? $name.'.'.$index : $name;
            if (!is_null($fileHandle)) {
                $this->model->setFile($fileHandle, $file);
            } else {
                $this->model->addFile($file);
            }
        }

        $pipelineJob->markEnded();

        $this->model->load('files');
        $this->pipeline->load('jobs');
        $this->model->touch();

        // Check if there is jobs waiting for the files created by this job and run it
        if (isset($newFile)) {
            foreach ($this->pipeline->jobs as $job) {
                if ($job->isWaitingForFile($name)) {
                    $job->run();
                }
            }
        }

        // Check if all jobs are ended
        if ($this->pipeline->allJobsEnded()) {
            if ($this->pipeline->hasFailedJobs()) {
                $this->pipeline->markFailed();
            } else {
                $this->pipeline->markEnded();
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
        $this->pipelineJob->markFailed($exception);
    }
}
