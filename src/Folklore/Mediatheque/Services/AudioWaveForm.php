<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Exception;

class AudioWaveForm implements AudioThumbnail
{
    /**
     * Get the thumbnail of a path
     * @param  string $source The source path
     * @param  string $destination The destination path
     * @param  array $options The options
     * @return string The path of the thumbnail
     */
    public function getThumbnail($source, $destination, $options = [])
    {
        $zoom = data_get($options, 'zoom', 600);
        $width = data_get($options, 'width', 1200);
        $height = data_get($options, 'height', 400);
        $backgroundColor = data_get($options, 'background_color', 'FFFFFF00');
        $color = data_get($options, 'color', '000000');
        $borderColor = data_get($options, 'border_color', null);
        $axisColor = data_get($options, 'axis_label_color', null);
        $axisLabel = data_get($options, 'axis_label', false);

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
