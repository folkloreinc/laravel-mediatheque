<?php

namespace Folklore\Mediatheque\Files;

use Folklore\Mediatheque\Contracts\FilesCreator;
use Illuminate\Support\Facades\Log;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class Mp4 implements FilesCreator
{
    public $quality = 20;

    protected $options;

    public function __constructor($options = null)
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

        try {
            $path = $file->getRealPath();
            $mp4Path = $path.'.mp4';

            if (!$keys) {
                return null;
            }

            $format = new X264();
            $ffmpeg = FFMpeg::create(config('mediatheque.programs.ffmpeg'));

            $format->setAdditionalParameters([
                '-y',
                '-preset',
                'slower',
                '-pix_fmt',
                'yuv420p',
                '-crf',
                $this->quality,
                '-movflags',
                '+faststart'
            ]);

            $ffmpegVideo = $ffmpeg->open($path);
            $ffmpegVideo->save($format, $mp4Path);

            return $mp4Path;
        } catch (\Exception $e) {
            Log::error($e);
            return null;
        }
    }
}
