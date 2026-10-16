<?php

namespace Folklore\Mediatheque\Testing;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\ProgressReporting;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;

/**
 * Completes every rendition at once with placeholder files, unless the test
 * made it fail or skip. Conditions (`when`) are checked against the probed
 * metadata, as a real transcoder does.
 */
class FakeTranscoder implements Transcoder
{
    /** @var array<string, TranscoderError> */
    public array $failures = [];

    /** @var array<string, string> */
    public array $skips = [];

    /** Runs are submitted, and their renditions stay queued. */
    public bool $hold = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Filesystems $filesystems,
    ) {}

    public function supports(string $operation): bool
    {
        return true;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(progress: ProgressReporting::Pushed, cancel: true);
    }

    public function submit(PipelineRun $run): ?string
    {
        if ($this->hold) {
            return 'fake-'.$run->key();
        }

        $metadata = $run->media()->metadata();

        foreach ($run->renditions() as $name => $rendition) {
            $job = $run->job($name);
            if ($job === null || $job->status()->isFinal()) {
                continue;
            }

            if (isset($this->failures[$name])) {
                $this->recorder->failed($job, $this->failures[$name]);
            } elseif (isset($this->skips[$name])) {
                $this->recorder->skipped($job, $this->skips[$name]);
            } elseif (! $rendition->when->matches($metadata)) {
                $this->recorder->skipped($job, 'The input doesn\'t match the rendition\'s condition.');
            } else {
                $this->recorder->started($job);
                $this->recorder->completed($job, $this->outputs($run, $rendition, $metadata));
            }
        }

        return 'fake-'.$run->key();
    }

    public function cancel(PipelineRun $run): void
    {
        foreach ($run->jobs() as $job) {
            $this->recorder->canceled($job);
        }
    }

    public function refresh(PipelineRun $run): void {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function outputs(PipelineRun $run, Rendition $rendition, array $metadata): Outputs
    {
        if (! $rendition->keep) {
            return Outputs::none();
        }

        [$mime, $extension] = self::format($rendition);
        $count = $rendition->options['count'] ?? 1;
        $count = is_int($count) && $count > 1 ? $count : null;
        $disk = $this->filesystems->disk($run->outputSource());

        $files = [];
        foreach (range(0, ($count ?? 1) - 1) as $index) {
            $path = $run->pathPrefix().'/'.$rendition->name.($count !== null ? '-'.$index : '').'.'.$extension;
            $disk->put($path, 'placeholder');
            $files[] = new OutputFile(
                path: $path,
                index: $count !== null ? $index : null,
                mime: $mime,
                size: strlen('placeholder'),
                width: is_int($metadata['width'] ?? null) ? $metadata['width'] : null,
                height: is_int($metadata['height'] ?? null) ? $metadata['height'] : null,
                duration: str_starts_with($mime, 'image/') ? null : (is_float($metadata['duration'] ?? null) ? $metadata['duration'] : null),
            );
        }

        return new Outputs($files);
    }

    /**
     * @return array{string, string}
     */
    private static function format(Rendition $rendition): array
    {
        return match (true) {
            $rendition->operation === 'video.transcode' && ($rendition->options['codec'] ?? null) === 'vp9' => ['video/webm', 'webm'],
            $rendition->operation === 'video.transcode' => ['video/mp4', 'mp4'],
            $rendition->operation === 'audio.transcode' => ['audio/mpeg', 'mp3'],
            $rendition->operation === 'audio.waveform' => ['image/png', 'png'],
            str_starts_with($rendition->operation, 'video.') => ['image/jpeg', 'jpg'],
            default => ['application/octet-stream', 'bin'],
        };
    }
}
