<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;
use Illuminate\Support\Facades\File;
use Streaming\FFMpeg as StreamingFFMpeg;
use Illuminate\Support\Str;

class HLS extends PipelineJob
{
    protected $defaultHlsOptions = [
        'segment_duration' => 10,
    ];

    public function __construct(FileContract $file, $options = [], HasFilesContract $model = null)
    {
        $this->options = array_merge($this->defaultHlsOptions, $this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
    }

    public function handle()
    {
        $path = $this->getLocalFilePath($this->file);

        $ffmpeg = StreamingFFMpeg::create(config('mediatheque.services.ffmpeg'));
        $media = $ffmpeg->open($path);

        $tempBasePath = sys_get_temp_dir() . '/mediatheque_pipeline_job_' . Str::random(8);
        $tempIndexPath = $tempBasePath . '/index.m3u8';

        $segmentDuration = data_get($this->options, 'segment_duration');
        $media
            ->hls()
            ->setHlsTime($segmentDuration)
            ->x264()
            ->autoGenerateRepresentations([1080, 720, 480, 360]) // TODO configurable
            ->save($tempIndexPath);

        $file = app(FileContract::class);
        $file->save();

        $destinationBasePath = $this->formatHlsBasePath(['id' => $file->id]);
        $destinationIndexPath = $destinationBasePath . '/index.m3u8';

        $file->setFile($tempIndexPath, [
            'mime' => 'application/vnd.apple.mpegurl',
            'path' => $destinationIndexPath,
        ]);

        // upload the rest of the files alongside the index file
        $source = $file->getSource();
        collect(glob($tempBasePath . '/*.{ts,m3u8}', GLOB_BRACE))
            ->filter(function ($file) {
                return basename($file) !== 'index.m3u8';
            })
            ->mapWithKeys(function ($file) use ($destinationBasePath) {
                $fileDestinationPath = $destinationBasePath . '/' . basename($file);
                return [
                    $fileDestinationPath => $file,
                ];
            })
            ->each(function ($localFile, $destination) use ($source) {
                $source->putFromLocalPath($destination, $localFile);
            });

        File::deleteDirectory($tempBasePath);

        return $file;
    }

    protected function formatHlsBasePath(...$replaces)
    {
        $format = data_get($this->options, 'hls_path_format', 'hls/{date(Y-m-d)}/{id}-{date(his)}');

        $destinationPath = app(PathFormatterService::class)->formatPath(
            $format,
            $this->options,
            ...$replaces
        );
        return $destinationPath;
    }
}
