<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Dimension as DimensionService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Dimension extends Reader
{
    public function getValue(string $path): ?ValueContract
    {
        $dimension = app(DimensionService::class)->getDimension($path);
        if (is_null($dimension)) {
            return null;
        }
        $values = [];
        foreach ($dimension as $key => $value) {
            $values[] = new Value($key, $value, 'integer');
        }
        return new Values($values);
    }
}
