<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
use Illuminate\Support\Facades\Log;
use Exception;

class AudioWaveForm implements ThumbnailCreatorContract
{
    /**
     * Create a thumbnail
     *
     * @param  string  $source
     * @param  string  $destination
     * @return boolean
     */
    public function createThumbnail($source, $destination)
    {
        $zoom = config('mediatheque.thumbnails.audio.zoom', 600);
        $width = config('mediatheque.thumbnails.audio.width', 1200);
        $height = config('mediatheque.thumbnails.audio.height', 400);
        $backgroundColor = config('mediatheque.thumbnails.audio.background_color', 'FFFFFF00');
        $color = config('mediatheque.thumbnails.audio.color', '000000');
        $borderColor = config('mediatheque.thumbnails.audio.border_color', null);
        $axisColor = config('mediatheque.thumbnails.audio.axis_label_color', null);
        $axisLabel = config('mediatheque.thumbnails.audio.axis_label', false);

        $command = [];
        $command[] = config('mediatheque.programs.audiowaveform.bin');
        $command[] = '-i '.escapeshellarg($source);
        $command[] = '-o '.escapeshellarg($destination.'.png');
        if (!empty($zoom)) {
            $command[] = '-z '.$zoom;
        }
        if (!empty($width)) {
            $command[] = '-w '.$width;
        }
        if (!empty($height)) {
            $command[] = '-h '.$height;
        }
        $command[] = '--background-color '.$backgroundColor;
        $command[] = '--waveform-color '.$color;
        if (!empty($borderColor)) {
            $command[] = '--border-color '.$borderColor;
        }
        if (!empty($axisColor)) {
            $command[] = '--axis-label-color '.$axisColor;
        }
        $command[] = $axisLabel ? '--with-axis-labels':'--no-axis-labels';
        $command[] = '2>&1';

        try {
            $output = [];
            $return = 0;
            exec(implode(' ', $command), $output, $return);

            if ($return !== 0) {
                throw new Exception('audiowaveform failed return code :'.$return.' '.implode(PHP_EOL, $output));
            }

            return $destination.'.png';
        } catch (Exception $e) {
            Log::error($e);
            return false;
        }
    }
}
