<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Duration as DurationService;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Duration extends Reader
{
    protected $name = 'duration';

    public function getValue(string $path): ?ValueContract
    {
        $value = app(DurationService::class)->getDuration($path);
        return !is_null($value)
            ? new Value($this->getName(), $value, 'float')
            : null;
    }
}
