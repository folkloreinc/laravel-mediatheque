<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\AudioThumbnail;
use Folklore\Mediatheque\Contracts\Services\Waveform;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Exception;

class AudioWaveForm implements AudioThumbnail, Waveform
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

        $args = [
            '-o',
            $destination,
        ];
        if (!empty($zoom)) {
            $args[] = '-z';
            $args[] = $zoom;
        }
        if (!empty($width)) {
            $args[] = '-w';
            $args[] = $width;
        }
        if (!empty($height)) {
            $args[] = '-h';
            $args[] = $height;
        }
        $args[] = '--background-color';
        $args[] = $backgroundColor;
        $args[] = '--waveform-color';
        $args[] = $color;
        if (!empty($borderColor)) {
            $args[] = '--border-color';
            $args[] = $borderColor;
        }
        if (!empty($axisColor)) {
            $args[] = '--axis-label-color';
            $args[] = $axisColor;
        }
        $args[] = $axisLabel ? '--with-axis-labels':'--no-axis-labels';

        $response = $this->runProcess($source, $args);

        return !is_null($response) ? $destination : null;
    }

    /**
     * Get the waveform of an audio path
     * @param  string $path The path of a file
     * @param  int $valuePerSeconds The number of value per seconds
     * @param  int $bits The number of bits
     * @return array The values
     */
    public function getWaveform(string $path, int $valuePerSeconds = 2, int $bits = 8): ?array
    {
        $args = [
            '--output-format',
            'json',
            '--pixels-per-second',
            $valuePerSeconds,
            '-b',
            $bits
        ];
        $response = $this->runProcess($path, $args);
        $data = @json_decode($response, true) ?? null;
        return data_get($data, 'data');
    }

    protected function runProcess(string $inputPath, $args = [])
    {
        $command = array_merge([
            config('mediatheque.services.audiowaveform.bin'),
            '-i',
            $inputPath,
            '-q'
        ], $args);
        try {
            $process = new Process($command);

            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return $process->getOutput();
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
