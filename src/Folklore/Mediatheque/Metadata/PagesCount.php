<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\PagesCount as PagesCountService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class PagesCount extends Reader
{
    protected $name = 'pages_count';

    public function getValue($path): ?ValueContract
    {
        $mime = app(MimeService::class)->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        $value = null;
        if (!preg_match('/^(audio|video|image)\//i', $mime)) {
            $value = app(PagesCountService::class)->getPagesCount($path);
        }
        return isset($value)
            ? new Value($this->getName(), $value, 'integer')
            : null;
    }
}
