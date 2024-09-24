<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;
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
        $indexPath = $this->formatDestinationPath($path);

        $ffmpeg = StreamingFFMpeg::create(config('mediatheque.services.ffmpeg'));
        $media = $ffmpeg->open($path);

        $segmentDuration = data_get($this->options, 'segment_duration');
        $media
            ->hls()
            ->setHlsTime($segmentDuration)
            ->x264()
            ->autoGenerateRepresentations([1080, 720, 480, 360]) // TODO configurable
            ->save($indexPath);

        $file = app(FileContract::class);
        $file->setFile($indexPath, ['mime' => 'application/vnd.apple.mpegurl']);
        // TODO upload the rest of the files alongside the index file
        dd($file);
        return $file;
    }

    protected function formatDestinationPath($path, ...$replaces)
    {
        $pathParts = pathinfo($path);
        $format = data_get(
            $this->options,
            'hls_path_format',
            '{dirname}/{filename}-{name}/index.m3u8'
        );

        $destinationPath = app(PathFormatterService::class)->formatPath(
            $format,
            [
                'name' => Str::slug(class_basename(get_class($this))),
            ],
            $pathParts,
            $this->options,
            ...$replaces
        );
        return $destinationPath;
    }
}
