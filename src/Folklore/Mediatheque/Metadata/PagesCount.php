<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\PagesCount as PagesCountService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class PagesCount extends Reader
{
    protected $name = 'pages_count';

    public function getValue(string $path): ?ValueContract
    {
        $value = app(PagesCountService::class)->getPagesCount($path);
        return !is_null($value)
            ? new Value($this->getName(), $value, 'integer')
            : null;
    }
}
