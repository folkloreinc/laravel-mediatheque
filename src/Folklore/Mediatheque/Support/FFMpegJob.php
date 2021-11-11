<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use FFMpeg\FFMpeg as BaseFFMpeg;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Exception\RuntimeException;

class FFMpegJob extends PipelineJob
{
    protected $format;

    protected $defaultFFmpegOptions = [
        'audio_codec' => null,
        'video_codec' => null,
        'passes' => null,
        'quality' => null,
        'resize' => null,
        'path_format' => '{dirname}/{filename}-{name}.{extension}',
        'extension' => 'mp4',
        'parameters' => [],
    ];

    public function __construct(FileContract $file, $options = [], HasFilesContract $model = null)
    {
        $this->options = array_merge($this->defaultFFmpegOptions, $this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
    }

    public function handle()
    {
        $path = $this->getLocalFilePath($this->file);
        $destinationPath = $this->formatDestinationPath($path);

        $format = $this->getFormat();

        $ffmpeg = BaseFFMpeg::create(config('mediatheque.services.ffmpeg'));
        $media = $ffmpeg->open($path);

        $this->applyFilters($media);

        $media->save($format, $destinationPath);

        return $this->makeFileFromPath($destinationPath);
    }

    protected function getFormat()
    {
        $formatClass = $this->format;
        $format = new $formatClass();

        $audioCodec = data_get($this->options, 'audio_codec', null);
        if (!is_null($audioCodec)) {
            $format->setAudioCodec($audioCodec);
        }

        $videoCodec = data_get($this->options, 'video_codec', null);
        if (!is_null($videoCodec)) {
            $format->setVideoCodec($videoCodec);
        }

        $passes = data_get($this->options, 'passes', null);
        if (!is_null($passes)) {
            $format->setPasses($passes);
        }

        $parameters = $this->getAdditionalParameters();
        if (!is_null($parameters)) {
            $format->setAdditionalParameters($parameters);
        }

        return $format;
    }

    protected function applyFilters($media)
    {
        $filters = $media->filters();
        $width = data_get($this->options, 'width', null);
        $height = data_get($this->options, 'height', null);
        if (!is_null($width) && !is_null($height)) {
            $filters->resize(new Dimension($width, $height), ResizeFilter::RESIZEMODE_FIT);
        } elseif (!is_null($height)) {
            $filters->resize(
                new Dimension($height, $height),
                ResizeFilter::RESIZEMODE_SCALE_HEIGHT
            );
        } elseif (!is_null($width)) {
            $filters->resize(new Dimension($width, $width), ResizeFilter::RESIZEMODE_SCALE_WIDTH);
        }

        $maxWidth = data_get($this->options, 'max_width', null);
        $maxHeight = data_get($this->options, 'max_height', null);
        $upscale = data_get($this->options, 'upscale', false);
        $needsResize = $upscale || $this->mediaNeedsResize($media, $maxWidth, $maxHeight);
        if ($needsResize && !is_null($maxWidth) && !is_null($maxHeight)) {
            $filters->resize(new Dimension($maxWidth, $maxHeight), ResizeFilter::RESIZEMODE_INSET);
        } elseif ($needsResize && !is_null($maxHeight)) {
            $filters->resize(
                new Dimension($maxHeight, $maxHeight),
                ResizeFilter::RESIZEMODE_SCALE_HEIGHT
            );
        } elseif ($needsResize && !is_null($maxWidth)) {
            $filters->resize(
                new Dimension($maxWidth, $maxWidth),
                ResizeFilter::RESIZEMODE_SCALE_WIDTH
            );
        }

        $rotation = data_get($this->options, 'rotation', null);
        if (!is_null($rotation)) {
            $filters->rotate($rotation);
        }
    }

    protected function mediaNeedsResize($media, $maxWidth, $maxHeight): bool
    {
        if (is_null($maxWidth) && is_null($maxHeight)) {
            return false;
        }
        $dimensions = $this->getMediaDimensions($media);
        if (is_null($dimensions)) {
            return false;
        }
        return (is_null($maxWidth) || $dimensions->getWidth() > $maxWidth) &&
            (is_null($maxHeight) || $dimensions->getHeight() > $maxHeight);
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

    protected function getAdditionalParameters()
    {
        $parameters = data_get($this->options, 'parameters', null);

        $quality = data_get($this->options, 'quality', null);
        if (!is_null($quality)) {
            $parameters[] = '-crf';
            $parameters[] = $quality;
        }

        $resize = data_get($this->options, 'resize', null);
        if (!is_null($resize)) {
            $parameters[] = '-vf';
            $parameters[] =
                'scale=' . data_get($resize, 0, '-1') . ':' . data_get($resize, 1, '-1');
        }

        return $parameters;
    }
}
