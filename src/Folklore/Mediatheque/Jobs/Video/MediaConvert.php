<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Support\MediaConvert\MediaConvertInput;
use Folklore\Mediatheque\Support\MediaConvert\MediaConvertJobSettings;
use Folklore\Mediatheque\Support\MediaConvert\MediaConvertOutput;
use FFMpeg\FFMpeg as BaseFFMpeg;
use Folklore\Mediatheque\Support\PipelineJob;
use FFMpeg\Filters\Video\ResizeFilter;
use Folklore\Mediatheque\Contracts\Services\MediaConvertClient;
use Illuminate\Support\Arr;

class MediaConvert extends PipelineJob
{
    public $config = [];

    protected $client;

    public function __construct(FileContract $file, $options = [], ?HasFilesContract $model = null)
    {
        $this->options = array_merge($this->config, $this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
        $this->client = app(MediaConvertClient::class);
    }

    public function handle()
    {
        $path = $this->getLocalFilePath($this->file);

        $inputs = data_get($this->options, 'inputs', null); // can batch
        $outputs = data_get($this->options, 'outputs', null); // can batch

        $format = data_get($this->options, 'format', 'h264');
        $extension = 'mp4';
        $width = 1920;
        $height = 1080;

        // $maxWidth = data_get($this->options, 'maxWidth', null);
        // $maxHeight = data_get($this->options, 'maxHeight', null);
        // $bitrate = data_get($this->options, 'bitrate', null);

        $jobSetting = new MediaConvertJobSettings(
            'MediaConvertJob',
            $this->formatS3DestinationPath($path),
            new MediaConvertInput($path),
            new MediaConvertOutput($format, ['aac'], $width, $height, $extension),
            Arr::except($this->options, ['inputs', 'outputs'])
        )->toArray();

        $this->client->createJob($jobSetting);

        return $path;
    }

    public function formatS3DestinationPath($path, $variables = [])
    {
        return 's3-bucket-name-thing';
    }

    // Do something with this cuz resize is gonna be difficult
    protected function getVideoSize($path)
    {
        $ffmpeg = BaseFFMpeg::create(
            array_merge(
                [
                    'timeout' => config('mediatheque.process_timeout', 600),
                ],
                config('mediatheque.services.ffmpeg')
            )
        );

        $media = $ffmpeg->open($path);

        $width = data_get($this->options, 'width', null);
        $height = data_get($this->options, 'height', null);
        if (!is_null($width) && !is_null($height)) {
            return [$width, $height, ResizeFilter::RESIZEMODE_FIT];
        } elseif (!is_null($height)) {
            return [$height, $height, ResizeFilter::RESIZEMODE_SCALE_HEIGHT];
        } elseif (!is_null($width)) {
            return [$width, $width, ResizeFilter::RESIZEMODE_SCALE_WIDTH];
        }

        $maxWidth = data_get($this->options, 'max_width', null);
        $maxHeight = data_get($this->options, 'max_height', null);
        $upscale = data_get($this->options, 'upscale', false);
        $needsResize = $upscale || $this->mediaNeedsResize($media, $maxWidth, $maxHeight);
        if ($needsResize && !is_null($maxWidth) && !is_null($maxHeight)) {
            return [$maxWidth, $maxHeight, ResizeFilter::RESIZEMODE_INSET];
        } elseif ($needsResize && !is_null($maxHeight)) {
            return [$maxHeight, $maxHeight, ResizeFilter::RESIZEMODE_SCALE_HEIGHT];
        } elseif ($needsResize && !is_null($maxWidth)) {
            return [$maxWidth, $maxWidth, ResizeFilter::RESIZEMODE_SCALE_WIDTH];
        }

        return null;
    }
}
