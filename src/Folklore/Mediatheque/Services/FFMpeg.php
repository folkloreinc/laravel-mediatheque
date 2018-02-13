<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Folklore\Mediatheque\Contracts\DimensionGetter;
use Folklore\Mediatheque\Contracts\DurationGetter;

use FFMpeg\FFProbe;
use FFMpeg\FFMpeg as BaseFFMpeg;
use FFMpeg\Coordinate\TimeCode;
use Exception;
use Illuminate\Support\Facades\Log;

class FFMpeg implements DimensionGetter, ThumbnailCreatorContract, DurationGetter
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getDuration($path)
    {
        $longestDuration = 0;
        try {
            $ffprobe = FFProbe::create(config('mediatheque.services.ffmpeg'));
            $streams = $ffprobe->streams($path);
            foreach ($streams->audios() as $stream) {
                $duration = $stream->get('duration');
                if ($duration > $longestDuration) {
                    $longestDuration = $duration;
                }
            }
            foreach ($streams->videos() as $stream) {
                $duration = $stream->get('duration');
                if ($duration > $longestDuration) {
                    $longestDuration = $duration;
                }
            }
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
        }

        return $longestDuration;
    }

    /**
     * Create a thumbnail
     *
     * @param  string  $source
     * @param  string  $destination
     * @return string
     */
    public function createThumbnail($source, $destination, $options = [])
    {
        $path = $source;
        $time = array_get($options, 'time', 0);
        if (preg_match('/^(.*)\[([0-9\.]+)\]$/', $source, $matches)) {
            $path = $matches[1];
            $time = (float)$matches[2];
        }
        $ffmpeg = BaseFFMpeg::create(config('mediatheque.services.ffmpeg'));
        $video = $ffmpeg->open($path);
        $video->frame(TimeCode::fromSeconds($time))
            ->save($destination);

        return $destination;
    }

    /**
     * Get dimension
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path)
    {
        try {
            $ffprobe = FFProbe::create(config('mediatheque.services.ffmpeg'));
            $stream = $ffprobe->streams($path)
                                ->videos()
                                ->first();
            $width = $stream->get('width');
            $height = $stream->get('height');
        } catch (Exception $e) {
            $width = 0;
            $height = 0;
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
        }

        return [
            'width' => $width,
            'height' => $height
        ];
    }
}
