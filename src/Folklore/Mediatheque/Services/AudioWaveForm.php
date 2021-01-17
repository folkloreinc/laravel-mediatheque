<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
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
    public function getThumbnail(string $source, string $destination, array $options = []): ?string
    {
        $zoom = data_get($options, 'zoom', 600);
        $width = data_get($options, 'width', 1200);
        $height = data_get($options, 'height', 400);
        $backgroundColor = data_get($options, 'background_color', 'FFFFFF00');
        $color = data_get($options, 'color', '000000');
        $borderColor = data_get($options, 'border_color', null);
        $axisColor = data_get($options, 'axis_label_color', null);
        $axisLabel = data_get($options, 'axis_label', false);

        $command = [
            config('mediatheque.services.audiowaveform.bin'),
            '-i',
            $source,
            '-o',
            $destination,
        ];
        if (!empty($zoom)) {
            $command[] = '-z';
            $command[] = $zoom;
        }
        if (!empty($width)) {
            $command[] = '-w';
            $command[] = $width;
        }
        if (!empty($height)) {
            $command[] = '-h';
            $command[] = $height;
        }
        $command[] = '--background-color';
        $command[] = $backgroundColor;
        $command[] = '--waveform-color';
        $command[] = $color;
        if (!empty($borderColor)) {
            $command[] = '--border-color';
            $command[] = $borderColor;
        }
        if (!empty($axisColor)) {
            $command[] = '--axis-label-color';
            $command[] = $axisColor;
        }
        $command[] = $axisLabel ? '--with-axis-labels':'--no-axis-labels';

        try {
            $process = new Process($command);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return $destination;
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
