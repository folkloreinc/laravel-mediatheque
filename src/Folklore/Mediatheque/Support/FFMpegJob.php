<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use FFMpeg\FFMpeg as BaseFFMpeg;

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
        'parameters' => []
    ];

    public function __construct(
        FileContract $file,
        $options = [],
        HasFilesContract $model = null
    ) {
        $this->options = array_merge(
            $this->defaultFFmpegOptions,
            $this->defaultOptions,
            $options
        );
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
        $media->save($format, $destinationPath);

        return $this->makeFileFromPath($destinationPath);
    }

    protected function getFormat()
    {
        $formatClass = $this->format;
        $format = new $formatClass();

        $audioCodec = array_get($this->options, 'audio_codec', null);
        if (!is_null($audioCodec)) {
            $format->setAudioCodec($audioCodec);
        }

        $videoCodec = array_get($this->options, 'video_codec', null);
        if (!is_null($videoCodec)) {
            $format->setVideoCodec($videoCodec);
        }

        $passes = array_get($this->options, 'passes', null);
        if (!is_null($passes)) {
            $format->setPasses($passes);
        }

        $parameters = $this->getAdditionalParameters();
        if (!is_null($parameters)) {
            $format->setAdditionalParameters($parameters);
        }

        return $format;
    }

    protected function getAdditionalParameters()
    {
        $parameters = array_get($this->options, 'parameters', null);

        $quality = array_get($this->options, 'quality', null);
        if (!is_null($quality)) {
            $parameters[] = '-crf';
            $parameters[] = $quality;
        }

        $resize = array_get($this->options, 'resize', null);
        if (!is_null($resize)) {
            $parameters[] = '-vf';
            $parameters[] =
                'scale=' .
                array_get($resize, 0, '-1') .
                ':' .
                array_get($resize, 1, '-1');
        }

        return $parameters;
    }
}