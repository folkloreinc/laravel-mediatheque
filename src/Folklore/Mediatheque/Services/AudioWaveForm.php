<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\ThumbnailCreator as ThumbnailCreatorContract;
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
    public function createThumbnail($source, $destination, $options = [])
    {
        $zoom = array_get($options, 'zoom', 600);
        $width = array_get($options, 'width', 1200);
        $height = array_get($options, 'height', 400);
        $backgroundColor = array_get($options, 'background_color', 'FFFFFF00');
        $color = array_get($options, 'color', '000000');
        $borderColor = array_get($options, 'border_color', null);
        $axisColor = array_get($options, 'axis_label_color', null);
        $axisLabel = array_get($options, 'axis_label', false);

        $command = [];
        $command[] = config('mediatheque.services.audiowaveform.bin');
        $command[] = '-i '.escapeshellarg($source);
        $command[] = '-o '.escapeshellarg($destination);
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

        $output = [];
        $return = 0;
        exec(implode(' ', $command), $output, $return);

        if ($return !== 0) {
            throw new Exception('audiowaveform failed return code :'.$return.' '.implode(PHP_EOL, $output));
        }

        return $destination;
    }
}
