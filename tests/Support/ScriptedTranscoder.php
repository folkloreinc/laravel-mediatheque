<?php

namespace Folklore\Mediatheque\Tests\Support;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * A transcoder for tests of the Eloquent side: it reports every rendition
 * through the recorder as soon as a run is submitted, failing the ones it
 * was told to, or holds them queued. It can cancel and refresh.
 */
class ScriptedTranscoder implements Transcoder
{
    /** @var list<string> */
    public array $failures = [];

    public bool $hold = false;

    /** @var list<string> The keys of the runs refreshed */
    public array $refreshed = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function supports(string $operation): bool
    {
        return true;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(progress: ProgressReporting::Pushed, cancel: true, refresh: true);
    }

    public function submit(PipelineRun $run): ?string
    {
        if ($this->hold) {
            return 'scripted-'.$run->key();
        }

        foreach (array_keys($run->renditions()) as $name) {
            $job = $run->job($name);
            if ($job === null) {
                continue;
            }

            if (in_array($name, $this->failures, true)) {
                $this->recorder->failed($job, new TranscoderError(TranscoderErrorCode::OperationFailed, 'Failed by the scripted transcoder.'));
            } else {
                $this->recorder->completed($job, Outputs::file(new OutputFile($run->pathPrefix().'/'.$name.'.bin', mime: 'application/octet-stream', size: 3, checksum: hash('sha256', $name))));
            }
        }

        return 'scripted-'.$run->key();
    }

    public function cancel(PipelineRun $run): void
    {
        foreach ($run->jobs() as $job) {
            $this->recorder->canceled($job);
        }
    }

    public function refresh(PipelineRun $run): void
    {
        $this->refreshed[] = $run->key();
    }
}
