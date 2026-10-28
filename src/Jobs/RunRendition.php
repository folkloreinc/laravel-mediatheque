<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Probe\LocalProber;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

/**
 * Produces one rendition of a run with the `ffmpeg` driver, in the queue.
 *
 * - Safe to retry: a rendition that already ended is left alone; a queue
 *   retry stays within the run and counts in `attempts`.
 * - Its input is the original, or the file of the rendition it reads from,
 *   which the same run wrote under its prefix.
 * - Its files are written under the run's prefix, with the media's
 *   visibility, then probed and reported to the recorder, an image with
 *   its ThumbHash. A rendition canceled while ffmpeg runs drops its output.
 * - Temporary files are removed, whatever happens.
 */
class RunRendition implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int|string $runId,
        public readonly string $rendition,
        ?string $queue = null,
    ) {
        $config = app(MediathequeConfig::class);
        $this->tries = $config->jobsTries();
        $this->timeout = $config->jobsTimeout();
        $this->onConnection($config->jobsConnection());
        $this->onQueue($queue ?? $config->jobsQueue());
    }

    public function handle(Recorder $recorder, FfmpegTranscoder $ffmpeg, LocalFiles $files, PathFormatter $paths, Filesystems $filesystems, LocalProber $prober, MediathequeConfig $config): void
    {
        [$run, $job] = $this->load();

        if ($run === null || $job === null || $job->status()->isFinal()) {
            return;
        }

        if ($job instanceof Model) {
            $job->forceFill(['attempts' => $job->attempts() + 1])->save();
        }

        $rendition = $job->rendition();
        $metadata = $run->media()->metadata();

        if (! $rendition->when->matches($metadata)) {
            $recorder->skipped($job, 'The input doesn\'t match the rendition\'s condition.');

            return;
        }

        $recorder->started($job);
        $operation = $ffmpeg->operation($rendition->operation);
        [$disk, $path] = $this->input($run, $rendition, $ffmpeg, $paths);
        $copy = $files->copy($disk, $path);
        $directory = $files->temporaryDirectory();

        try {
            $outputs = $operation->run(new OperationContext(
                input: $copy->path,
                outputDirectory: $directory,
                rendition: $rendition,
                metadata: $metadata,
                progress: fn (float $progress) => $recorder->progress($job, $progress),
                timeout: $config->jobsTimeout(),
            ));

            [, $current] = $this->load();
            if ($current === null || $current->status()->isFinal()) {
                return;
            }

            $recorder->completed($current, $this->store($run, $rendition, $operation->extension($rendition), $outputs, $paths, $filesystems, $prober));
            $ffmpeg->dispatchDependents($run, $rendition->name);
        } catch (OperationFailed $exception) {
            $recorder->failed($job, $exception->error);
        } finally {
            $copy->cleanup();
            LocalFiles::removeDirectory($directory);
        }
    }

    /**
     * After the last try: the rendition fails, and the renditions that read
     * from it.
     */
    public function failed(Throwable $exception): void
    {
        [, $job] = $this->load();

        if ($job !== null) {
            app(Recorder::class)->failed($job, new TranscoderError(
                $exception instanceof FilesystemException ? TranscoderErrorCode::StorageFailed : TranscoderErrorCode::OperationFailed,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * The run, loaded without the application's global scopes, and the job.
     *
     * @return array{PipelineRun|null, PipelineJob|null}
     */
    private function load(): array
    {
        $run = Models::pipelineRun()::query()->withoutGlobalScopes()->with('pipelineJobs')->find($this->runId);

        if (! $run instanceof PipelineRun) {
            return [null, null];
        }

        return [$run, $run->job($this->rendition)];
    }

    /**
     * The disk and path of the rendition's input.
     *
     * @return array{string, string}
     */
    private function input(PipelineRun $run, Rendition $rendition, FfmpegTranscoder $ffmpeg, PathFormatter $paths): array
    {
        if ($rendition->from === null) {
            $original = $run->input();

            return [
                $original->source() ?? throw new RuntimeException('The original is not stored on a disk.'),
                $original->path() ?? throw new RuntimeException('The original has no path.'),
            ];
        }

        $from = $run->renditions()[$rendition->from];

        return [$run->outputSource(), $paths->runFile($run, $from->name, null, $ffmpeg->operation($from->operation)->extension($from))];
    }

    /**
     * Write the outputs under the run's prefix, with the media's visibility.
     * A rendition that is not kept is written for the renditions that read
     * from it, and reported without files.
     *
     * @param  list<LocalOutput>  $outputs
     */
    private function store(PipelineRun $run, Rendition $rendition, string $extension, array $outputs, PathFormatter $paths, Filesystems $filesystems, LocalProber $prober): Outputs
    {
        $disk = $filesystems->disk($run->outputSource());
        $files = [];

        foreach ($outputs as $output) {
            $index = count($outputs) > 1 || $output->index !== null ? $output->index : null;
            $path = $paths->runFile($run, $rendition->name, $index, $extension);
            $stream = fopen($output->path, 'rb') ?: throw new RuntimeException(sprintf('Can\'t read the output "%s".', $output->path));

            try {
                $disk->writeStream($path, $stream, ['visibility' => $run->visibility()->value, 'ContentType' => $output->mime]);
            } finally {
                if (get_resource_type($stream) === 'stream') {
                    fclose($stream);
                }
            }

            $probed = $prober->probePath($output->path, $output->mime);
            $metadata = $probed->isDone() ? $probed->metadata : [];

            $files[] = new OutputFile(
                path: $path,
                index: $index,
                mime: $output->mime,
                size: (int) filesize($output->path),
                checksum: (string) hash_file('sha256', $output->path),
                width: is_int($metadata['width'] ?? null) ? $metadata['width'] : null,
                height: is_int($metadata['height'] ?? null) ? $metadata['height'] : null,
                duration: is_float($metadata['duration'] ?? null) ? $metadata['duration'] : null,
                bitrate: is_int($metadata['bitrate'] ?? null) ? $metadata['bitrate'] : null,
                metadata: is_string($metadata[MetadataKey::Thumbhash->value] ?? null) ? [MetadataKey::Thumbhash->value => $metadata[MetadataKey::Thumbhash->value]] : [],
            );
        }

        return $rendition->keep ? new Outputs($files) : Outputs::none();
    }
}
