<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Waveform
{
    /**
     * Get the waveform of an audio path
     * @param  string $path The path of a file
     * @param  int $valuePerSeconds The number of value per seconds
     * @param  int $bits The number of bits
     * @return array The values
     */
    public function getWaveform(string $path, int $valuePerSeconds = 1, int $bits = 8): ?array;
}
