<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Services\Waveform as WaveformService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Waveform extends Reader
{
    protected $bits;

    protected $valuePerSeconds;

    public function __construct(array $config = [])
    {
        $this->bits = Arr::get($config, 'bits', 8);
        $this->valuePerSeconds = Arr::get($config, 'value_per_seconds', 10);
    }

    public function getValue(string $path): ?ValueContract
    {
        $values = app(WaveformService::class)->getWaveform($path, $this->valuePerSeconds, $this->bits);
        if (is_null($values)) {
            return null;
        }
        return new Value($this->getName(), $values, 'json');
    }
}
