<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;
use Illuminate\Support\Facades\File;
use Streaming\FFMpeg as StreamingFFMpeg;
use Illuminate\Support\Str;
use RuntimeException;
use Streaming\Media;
use Streaming\Representation;

class HLS extends PipelineJob
{
    protected $defaultHlsOptions = [
        'segment_duration' => 5,
        'default_audio_bitrate' => 128,
        'representations' => [
            [
                'max_width' => 360,
                'max_height' => 360,
                'bitrate' => 800,
            ],
            [
                'max_width' => 720,
                'max_height' => 720,
                'bitrate' => 2000,
            ],
            [
                'max_width' => 1080,
                'max_height' => 1080,
                'bitrate' => 4000,
            ],
        ],
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
        $mediaDimensions = $this->getMediaDimensions($media);

        $tempBasePath = sys_get_temp_dir() . '/mediatheque_pipeline_job_' . Str::random(8);
        $tempIndexPath = $tempBasePath . '/index.m3u8';

        $segmentDuration = data_get($this->options, 'segment_duration');
        $defaultAudioBitrate = data_get($this->options, 'default_audio_bitrate');
        $representations = collect(data_get($this->options, 'representations'))
            ->filter(function ($spec, $index) use ($mediaDimensions) {
                // if media height is less than or equal to max height
                // for the first spec, include it anyway so that we have at least one representation
                if ($index === 0) {
                    return true;
                }

                $mediaWidth = $mediaDimensions->getWidth();
                $mediaHeight = $mediaDimensions->getHeight();
                $maxWidthForSpec = data_get($spec, 'max_width');
                $maxHeightForSpec = data_get($spec, 'max_height');
                return $mediaWidth >= $maxWidthForSpec || $mediaHeight >= $maxHeightForSpec;
            })
            ->map(function ($spec) use ($mediaDimensions, $defaultAudioBitrate) {
                $maxWidth = data_get($spec, 'max_width');
                $maxHeight = data_get($spec, 'max_height');
                $mediaWidth = $mediaDimensions->getWidth();
                $mediaHeight = $mediaDimensions->getHeight();
                $mediaAspectRatio = $mediaWidth / $mediaHeight;

                if ($mediaWidth === $mediaHeight) {
                    // square video
                    $width = $maxWidth;
                    $height = $maxHeight;
                } elseif ($mediaWidth < $mediaHeight) {
                    // portrait video
                    $width = $maxWidth;
                    $height = (int) floor($width / $mediaAspectRatio);
                } else {
                    // landscape video
                    $height = $maxHeight;
                    $width = (int) floor($height * $mediaAspectRatio);
                }

                return (new Representation())
                    ->setResize($width, $height)
                    ->setKiloBitrate(data_get($spec, 'bitrate'))
                    ->setAudioKiloBitrate(data_get($spec, 'audio_bitrate', $defaultAudioBitrate));
            })
            ->toArray();

        $media
            ->hls()
            ->setHlsTime($segmentDuration)
            ->x264()
            ->addRepresentations($representations)
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

    protected function getMediaDimensions($media)
    {
        $dimensions = null;
        foreach ($media->getStreams() as $stream) {
            if ($stream->isVideo()) {
                try {
                    $dimensions = $stream->getDimensions();
                    break;
                } catch (RuntimeException $e) {
                }
            }
        }
        return $dimensions;
    }
}
