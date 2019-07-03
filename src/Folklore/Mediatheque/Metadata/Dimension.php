<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\Dimension as DimensionService;

class Dimension extends Reader
{
    public function hasMultipleValues()
    {
        return true;
    }

    public function getValue($path)
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
        return $values;
    }
}
