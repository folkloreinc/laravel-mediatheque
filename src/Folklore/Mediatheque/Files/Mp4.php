<?php

namespace Folklore\Mediatheque\Files;

use Folklore\Mediatheque\Contracts\FilesCreator;
use Illuminate\Support\Facades\Log;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class Mp4 implements FilesCreator
{
    protected $options = [];

    public function __constructor($options = [])
    {
        $this->options = $options;
    }

    public function getKeysOfFilesToCreate($file)
    {
        // Should create a single MP4 file
        return true;
    }

    /**
     * Create files from path
     *
     * @param  string  $file
     * @param  array   [$keys]
     * @return array   $files
     */
    public function createFiles($file, $keys = null)
    {
        if (is_null($keys)) {
            $keys = $this->getKeysOfFilesToCreate($file);
        }

        $options = array_merge([
            'audio_codec' => 'aac',
            'passes' => 1,
            'quality' => 20,
        ], config('mediatheque.mp4'), $this->options);

        try {
            $path = $file->getRealPath();
            $mp4Path = $path.'.mp4';

            if (!$keys) {
                return null;
            }

            $audioCodec = $options['audio_codec'];
            $format = new X264($audioCodec);
            $format->setPasses($options['passes']);
            $ffmpeg = FFMpeg::create(config('mediatheque.services.ffmpeg'));

            $parameters = [
                '-y',
                '-preset',
                'slower',
                '-pix_fmt',
                'yuv420p',
                '-profile:v',
                'baseline',
                '-crf',
                $options['quality'],
                '-movflags',
                '+faststart'
            ];
            if ($audioCodec === 'aac') {
                $parameters[] = '-strict';
                $parameters[] = '-2';
            }
            $format->setAdditionalParameters($parameters);

            $ffmpegVideo = $ffmpeg->open($path);
            $ffmpegVideo->save($format, $mp4Path);

            return $mp4Path;
        } catch (\Exception $e) {
            Log::error($e);
            return null;
        }
    }
}
