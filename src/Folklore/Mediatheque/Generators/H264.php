<?php

namespace Folklore\Mediatheque\Generators;

use Folklore\Mediatheque\Support\Generator;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Sources\LocalSource;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class H264 extends Generator
{
    protected $defaultOptions = [
        'audio_codec' => 'aac',
        'passes' => 1,
        'quality' => 20,
        'extension' => '.mp4',
        'parameters' => [
            '-y',
            '-preset',
            'slower',
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'baseline',
            '-movflags',
            '+faststart',
        ]
    ];

    public function handle(FileContract $file, HasFilesContract $model)
    {
        $source = $file->getSource();
        if ($source instanceof LocalSource) {
            $path = $source->getFullPath($file->path);
        } else {
            $extension = pathinfo($file->path, PATHINFO_EXTENSION);
            $path = tempnam(sys_get_temp_dir(), 'h264').'.'.$extension;
            $file->downloadFile($path);
        }
        $mp4Path = $path.array_get($this->options, 'extension', '');

        $audioCodec = array_get($this->options, 'audio_codec', 'aac');
        $passes = array_get($this->options, 'passes', 1);
        $format = new X264($audioCodec);
        $format->setPasses($passes);

        $parameters = array_get($this->options, 'parameters', null);
        $quality = array_get($this->options, 'quality', null);
        if (!is_null($quality)) {
            $parameters[] = '-crf';
            $parameters[] = $quality;
        }
        if ($audioCodec === 'aac') {
            $parameters[] = '-strict';
            $parameters[] = '-2';
        }
        $format->setAdditionalParameters($parameters);

        $ffmpeg = FFMpeg::create(config('mediatheque.programs.ffmpeg'));
        $ffmpegVideo = $ffmpeg->open($path);
        $ffmpegVideo->save($format, $mp4Path);

        $newFile = app(FileContract::class);
        $newFile->setFile($mp4Path);

        return $newFile;
    }
}
