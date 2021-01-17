<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\VideoThumbnail;
use Folklore\Mediatheque\Contracts\Services\VideoDimension;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Folklore\Mediatheque\Contracts\Services\AudioDuration;

use FFMpeg\FFProbe;
use FFMpeg\FFMpeg as BaseFFMpeg;
use FFMpeg\Coordinate\TimeCode;
use Exception;
use Illuminate\Support\Facades\Log;

class FFMpeg implements VideoThumbnail, VideoDimension, VideoDuration, AudioDuration
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getDuration(string $path): ?float
    {
        try {
            $ffprobe = FFProbe::create(config('mediatheque.services.ffmpeg'));
            $streams = $ffprobe->streams($path);

            return collect($streams->all())->reduce(function ($longestDuration, $stream) {
                $duration = $stream->get('duration');
                return !is_null($duration) && (float) $duration > $longestDuration
                    ? (float) $duration
                    : $longestDuration;
            }, 0);
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
            return null;
        }
    }

    /**
     * Get the thumbnail of a path
     * @param  string $source The source path
     * @param  string $destination The destination path
     * @param  array $options The options
     * @return string The path of the thumbnails
     */
    public function getThumbnail(string $source, string $destination, array $options = []): ?string
    {
        $path = $source;
        $time = data_get($options, 'time', 0);
        if (preg_match('/^(.*)\[([0-9\.]+)\]$/', $source, $matches)) {
            $path = $matches[1];
            $time = (float) $matches[2];
        }
        $ffmpeg = BaseFFMpeg::create(config('mediatheque.services.ffmpeg'));
        $video = $ffmpeg->open($path);
        $video->frame(TimeCode::fromSeconds($time))->save($destination);

        return $destination;
    }

    /**
     * Get dimension
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension(string $path): ?array
    {
        try {
            $ffprobe = FFProbe::create(config('mediatheque.services.ffmpeg'));
            $stream = $ffprobe
                ->streams($path)
                ->videos()
                ->first();
            $width = $stream->get('width');
            $height = $stream->get('height');

            return [
                'width' => $width,
                'height' => $height,
            ];
        } catch (Exception $e) {
            if (config('mediatheque.debug')) {
                throw $e;
            } else {
                Log::error($e);
            }
            return null;
        }
    }
}
