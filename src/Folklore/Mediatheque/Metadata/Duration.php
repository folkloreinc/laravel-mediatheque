<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Duration as DurationService;
use Folklore\Mediatheque\Contracts\Services\VideoDuration;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Duration extends Reader
{
    protected $name = 'duration';

    public function getValue($path): ?ValueContract
    {
        $mime = app(MimeService::class)->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        $value = app(DurationService::class)->getDuration($path);
        return isset($value)
            ? new Value($this->getName(), $value, 'float')
            : null;
    }
}
