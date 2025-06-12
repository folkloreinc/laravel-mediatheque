<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Jobs\Video\MediaConvert\MediaConvertInput;
use Folklore\Mediatheque\Jobs\Video\MediaConvert\MediaConvertJobSettings;
use Folklore\Mediatheque\Jobs\Video\MediaConvert\MediaConvertJob;
use Folklore\Mediatheque\Jobs\Video\MediaConvert\MediaConvertOutput;
use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Services\MediaConvertClient;
use Illuminate\Support\Arr;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Illuminate\Filesystem\AwsS3V3Adapter;

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
        $file = $this->file;
        $source = $file->getSource();
        if (!$source instanceof FilesystemSource) {
            throw new \Exception('MediaConvert job requires a FilesystemSource');
        }
        $disk = $source->getDisk();
        if(!$disk instanceof AwsS3V3Adapter) {
            throw new \Exception('MediaConvert job requires an S3 disk');
        }
        $bucket = config('filesystems.disks.s3.bucket');

        $path = $this->formatS3SourcePath(
            $bucket,
            $file->path
        );

        $fileWidth = $file->getMetadata('width')->getValue();
        $fileHeight = $file->getMetadata('height')->getValue();

        $inputs = data_get($this->options, 'inputs', null); // can batch
        $outputs = data_get($this->options, 'outputs', null); // can batch
        $bitrate = data_get($this->options, 'bitrate', null);

        $format = data_get($this->options, 'format', 'h264');
        $extension = 'mp4';
        $width = $fileWidth;
        $height = $fileHeight;
        $scaling = null;

        $size = $this->getVideoSize($fileWidth, $fileHeight);
        if (!is_null($size)) {
            $width = $size['width'] ?? $width;
            $height = $size['height'] ?? $height;
            $scaling = $size['scaling'] ?? 'DEFAULT';
        }

        $info = pathinfo($this->file->path);
        $destination = $this->formatS3DestinationPath($bucket, ($info['dirname'] ?? ''));

        $settings = new MediaConvertJobSettings(
            'MediaConvertJob',
            $destination,
            new MediaConvertInput($path),
            is_array($format) ? collect($format)->map(function($fmt) use ($width, $height, $scaling, $extension) {
                return new MediaConvertOutput($fmt, ['aac'], $width, $height, $scaling, $extension);
            })->toArray() : new MediaConvertOutput($format, ['aac'], $width, $height, $scaling, $extension),
            Arr::except($this->options, ['inputs', 'outputs'])
        );

        $job = new MediaConvertJob(
            config('mediatheque.services.mediaConvert.queue', null),
            config('mediatheque.services.mediaConvert.role', null),
            $settings
        )->toArray();

        $this->client->createJob($job);

        return 's3://'.$destination;
    }

    public function formatS3DestinationPath($bucket, $path)
    {
        return 's3://'.$bucket.'/'.rtrim(ltrim($path, '/'), '/').'/converted';
    }

    public function formatS3SourcePath($bucket, $path)
    {
        return 's3://'.$bucket.'/'.ltrim($path, '/');
    }

    // Do something with this cuz resize is gonna be difficult
    protected function getVideoSize($fileWidth, $fileHeight)
    {
        $width = data_get($this->options, 'width', null);
        $height = data_get($this->options, 'height', null);

        if (!is_null($width) && !is_null($height)) {
            return [
                'width' => $width,
                'height' => $height,
                'scaling' => 'DEFAULT' //
            ];
        } elseif (!is_null($height)) {
            return [
                'width' => null,
                'height' => $height,
                'scaling' => 'FIT' // ResizeFilter::RESIZEMODE_SCALE_HEIGHT
            ];
        } elseif (!is_null($width)) {
            return [
                'width' => $width,
                'height' => null,
                'scaling' => 'FIT' // ResizeFilter::RESIZEMODE_SCALE_WIDTH
            ];
        }

        $maxWidth = data_get($this->options, 'max_width', null);
        $maxHeight = data_get($this->options, 'max_height', null);
        $upscale = data_get($this->options, 'upscale', false);

        $needsResize = $upscale || $this->mediaNeedsResize($fileWidth, $fileHeight, $maxWidth, $maxHeight);

        if ($needsResize && !is_null($maxWidth) && !is_null($maxHeight)) {
            return [
                'width' => $maxWidth,
                'height' => $maxHeight,
                'scaling' => 'FILL' // ResizeFilter::RESIZEMODE_INSET
            ];
        } elseif ($needsResize && !is_null($maxHeight)) {
            return [
                'width' => null,
                'height' => $maxHeight,
                'scaling' => 'FIT' // ResizeFilter::RESIZEMODE_SCALE_HEIGHT
            ];
        } elseif ($needsResize && !is_null($maxWidth)) {
            return [
                'width' => $maxWidth,
                'height' => null,
                'scaling' => 'FIT' // ResizeFilter::RESIZEMODE_SCALE_WIDTH
            ];
        }

        return null;
    }

    protected function mediaNeedsResize($fileWidth, $fileHeight, $maxWidth, $maxHeight): bool
    {
        if (is_null($fileWidth) || is_null($fileHeight)) {
            return false;
        }
        if (is_null($maxWidth) && is_null($maxHeight)) {
            return false;
        }
        return (is_null($maxWidth) || $fileWidth > $maxWidth) &&
            (is_null($maxHeight) || $fileHeight > $maxHeight);
    }
}
