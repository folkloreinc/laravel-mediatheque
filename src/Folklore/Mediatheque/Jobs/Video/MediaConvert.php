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
use Folklore\Mediatheque\Metadata\Value as MetadataValue;

use Illuminate\Support\Arr;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Illuminate\Filesystem\AwsS3V3Adapter;

class MediaConvert extends PipelineJob
{
    public $config = [];

    protected $client;

    protected $formats = [
        'h264' => [
            'container' => 'mp4',
            'audio' => ['aac'],
            'mime' => 'video/mp4',
        ],
        'h265' => [
            'container' => 'mp4',
            'audio' => ['aac'],
            'mime' => 'video/mp4',
        ],
        'webm' => [
            'container' => 'webm',
            'audio' => ['opus'],
            'mime' => 'video/webm',
        ],
    ];

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
        if (!$disk instanceof AwsS3V3Adapter) {
            throw new \Exception('MediaConvert job requires an S3 disk');
        }

        $source = config('mediatheque.source');
        $name = config('mediatheque.sources.' . $source . '.disk', null);
        $disk = config('filesystems.' . $name, null);
        $driver = config('filesystems.disks.' . $disk . '.driver');
        if ($driver !== 's3') {
            throw new \Exception('MediaConvert job requires an S3 disk');
        }
        $bucket = config('filesystems.disks.' . $disk . '.bucket');

        $path = $this->formatS3SourcePath($bucket, $file->path);

        $fileWidth = $file->getMetadata('width')->getValue();
        $fileHeight = $file->getMetadata('height')->getValue();

        // TODO
        // $inputs = data_get($this->options, 'inputs', null);
        // $outputs = data_get($this->options, 'outputs', null);

        $bitrate = data_get($this->options, 'bitrate', null);
        $videoFormat = data_get(
            $this->options,
            'formats',
            data_get($this->options, 'format', 'h264')
        );
        $formats = is_array($videoFormat) ? $videoFormat : [$videoFormat];

        $width = $fileWidth;
        $height = $fileHeight;
        $scaling = null;

        $size = $this->getVideoSize($fileWidth, $fileHeight);
        if (!is_null($size)) {
            $width = $size['width'];
            $height = $size['height'];
            $scaling = $size['scaling'] ?? 'DEFAULT';
        }

        $info = pathinfo($file->path);
        $destination = $this->formatS3DestinationPath($bucket, $info['dirname'] ?? '');

        $settings = new MediaConvertJobSettings(
            'MediaConvertJob',
            $destination,
            new MediaConvertInput($path),
            collect($formats)
                ->map(function ($format) use ($width, $height, $scaling, $bitrate) {
                    $config = data_get($this->formats, $format, null);
                    return new MediaConvertOutput(
                        $format,
                        $config['audio'],
                        $width,
                        $height,
                        $scaling,
                        ['videoBitrate' => $bitrate]
                    );
                })
                ->toArray(),
            Arr::except($this->options, ['inputs', 'outputs', 'bitrate', 'formats', 'format'])
        );

        $config = (new MediaConvertJob(
            config('mediatheque.services.mediaConvert.queue', null),
            config('mediatheque.services.mediaConvert.role', null),
            $settings
        ))->toArray();

        $job = $this->client->createJob($config);

        if (!$job) {
            throw new \Exception('Failed to create MediaConvert job');
        }

        while (!$this->client->isJobComplete($job)) {
            sleep(10);
        }

        $job = $this->client->getJob(data_get($job, 'Job.Id'));

        $output = data_get($job, 'Job.OutputGroupDetails.0.OutputDetails', []);

        $values = collect($formats)
            ->map(function ($format, $index) use ($info, $output) {
                $config = data_get($this->formats, $format, null);
                $path =
                    $this->formatS3Destination($info['dirname']) .
                    $info['filename'] .
                    ($format === 'h264' || $format === 'h265' ? '.mp4' : '.' . $format);

                $metadata = data_get($output, $index, []);
                $duration = (float) ((int) data_get($metadata, 'DurationInMs', 0)) / 1000;
                $width = data_get($metadata, 'VideoDetails.WidthInPx', null);
                $height = data_get($metadata, 'VideoDetails.HeightInPx', null);
                $data = array_merge($config, [
                    'path' => $path,
                    'format' => $format,
                    'size' => 0, // TODO: get size from S3 filesystem afterwards
                    'remote' => true,
                    'metadata' => [
                        'duration' => new MetadataValue('duration', $duration, 'float'),
                        'width' => new MetadataValue('width', $width, 'integer'),
                        'height' => new MetadataValue('height', $height, 'integer'),
                        'format' => new MetadataValue('format', $format, 'string'),
                    ],
                ]);
                return $data;
            })
            ->toArray();

        $files = [];
        foreach ($values as $data) {
            $path = data_get($data, 'path');
            $format = data_get($data, 'format');
            $file = app(FileContract::class);
            $file->setFileFromSource($path, $data);
            $file->save();
            $files[$format] = $file;
        }

        return $files;
    }

    public function formatS3DestinationPath($bucket, $path)
    {
        return 's3://' . $bucket . '/' . $this->formatS3Destination($path);
    }

    public function formatS3Destination($path)
    {
        $tempPath = config('mediatheque.services.mediaConvert.temp_path', 'converted');
        return rtrim(ltrim($path, '/'), '/') . '/' . rtrim(ltrim($tempPath, '/'), '/') . '/';
    }

    public function formatS3SourcePath($bucket, $path)
    {
        return 's3://' . $bucket . '/' . ltrim($path, '/');
    }

    protected function getVideoSize($fileWidth, $fileHeight)
    {
        $width = data_get($this->options, 'width', null);
        $height = data_get($this->options, 'height', null);

        if (!is_null($width) && !is_null($height)) {
            return [
                'width' => $width,
                'height' => $height,
                'scaling' => 'DEFAULT',
            ];
        } elseif (!is_null($height)) {
            return [
                'width' => null,
                'height' => $height,
                'scaling' => 'FIT',
            ];
        } elseif (!is_null($width)) {
            return [
                'width' => $width,
                'height' => null,
                'scaling' => 'FIT',
            ];
        }

        $maxWidth = data_get($this->options, 'max_width', null);
        $maxHeight = data_get($this->options, 'max_height', null);
        $upscale = data_get($this->options, 'upscale', false);

        $needsResize =
            $upscale || $this->mediaNeedsResize($fileWidth, $fileHeight, $maxWidth, $maxHeight);

        if ($needsResize && !is_null($maxWidth) && !is_null($maxHeight)) {
            return [
                'width' => $maxWidth,
                'height' => $maxHeight,
                'scaling' => 'FILL', // TEST THIS: ResizeFilter::RESIZEMODE_INSET
            ];
        } elseif ($needsResize && !is_null($maxHeight)) {
            return [
                'width' => null,
                'height' => $maxHeight,
                'scaling' => 'FIT',
            ];
        } elseif ($needsResize && !is_null($maxWidth)) {
            return [
                'width' => $maxWidth,
                'height' => null,
                'scaling' => 'FIT',
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
