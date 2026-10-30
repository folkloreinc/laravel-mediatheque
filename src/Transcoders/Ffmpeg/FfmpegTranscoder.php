<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\AudioTranscode;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\AudioWaveform;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\DocumentThumbnails;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\ImageConvert;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\VideoPoster;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\VideoThumbnails;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operations\VideoTranscode;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The local driver (docs/decisions/0005): each rendition of a run is a job
 * of the application's queue, which runs ffmpeg and reports through the
 * recorder. Renditions that read from another one are dispatched when it
 * completes. It has no driver job id.
 */
class FfmpegTranscoder implements Transcoder
{
    /** @var array<string, class-string<Operation>> */
    public const array OPERATIONS = [
        'video.transcode' => VideoTranscode::class,
        'video.poster' => VideoPoster::class,
        'video.thumbnails' => VideoThumbnails::class,
        'audio.transcode' => AudioTranscode::class,
        'audio.waveform' => AudioWaveform::class,
        'image.convert' => ImageConvert::class,
        'document.thumbnails' => DocumentThumbnails::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Jobs $jobs,
    ) {}

    public function supports(string $operation): bool
    {
        return isset(self::OPERATIONS[$operation]);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(progress: ProgressReporting::Pushed, cancel: true);
    }

    public function operation(string $name): Operation
    {
        $class = self::OPERATIONS[$name] ?? throw new InvalidArgumentException(sprintf('The ffmpeg transcoder has no operation "%s".', $name));

        return $this->container->make($class);
    }

    /**
     * Dispatch the renditions that read from the original.
     */
    public function submit(PipelineRun $run): ?string
    {
        foreach ($run->renditions() as $rendition) {
            if ($rendition->from === null) {
                $this->dispatch($run, $rendition);
            }
        }

        return null;
    }

    /**
     * Dispatch the renditions that read from one that just completed.
     */
    public function dispatchDependents(PipelineRun $run, string $completed): void
    {
        foreach ($run->renditions() as $rendition) {
            if ($rendition->from === $completed) {
                $this->dispatch($run, $rendition);
            }
        }
    }

    /**
     * Cancel the renditions that haven't ended. A rendition that is running
     * finishes its process, and its output is dropped.
     */
    public function cancel(PipelineRun $run): void
    {
        $recorder = $this->container->make(Recorder::class);

        foreach ($run->jobs() as $job) {
            if (! $job->status()->isFinal()) {
                $recorder->canceled($job);
            }
        }
    }

    public function refresh(PipelineRun $run): void {}

    private function dispatch(PipelineRun $run, Rendition $rendition): void
    {
        $job = $run->job($rendition->name);

        if (! $run instanceof Model || $job === null || $job->status() !== JobStatus::Queued) {
            return;
        }

        $key = $run->getKey();
        if (is_int($key) || is_string($key)) {
            $this->jobs->dispatch(new RunRendition($key, $rendition->name, $rendition->queue));
        }
    }
}
