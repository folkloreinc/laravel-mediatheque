<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Dimension as DimensionService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Dimension extends Reader
{
    public function getValue($path): ?ValueContract
    {
        $mime = app(MimeService::class)->getMime($path);
        if (is_null($mime)) {
            return [];
        }
        $dimension = app(DimensionService::class)->getDimension($path);
        $values = [];
        if (isset($dimension)) {
            foreach ($dimension as $key => $value) {
                $values[] = new Value($key, $value, 'integer');
            }
        }
        return sizeof($values) ? new Values($values) : null;
    }
}
