<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Services\Color as ColorService;
use Folklore\Mediatheque\Contracts\Services\Palette as PaletteService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Colors extends Reader
{
    protected $type;

    protected $count;

    public function __construct(array $config = [])
    {
        $this->type = Arr::get($config, 'type', 'representative');
        $this->count = Arr::get($config, 'count', 5);
    }

    public function getValue(string $path): ?ValueContract
    {
        $colors =
            $this->type === 'representative'
                ? app(PaletteService::class)->getPalette($path, $this->count)
                : app(ColorService::class)->getColors($path, $this->count);
        if (is_null($colors)) {
            return null;
        }
        $values = [];
        foreach ($colors as $key => $value) {
            $values[] = new Value($key, $value, 'string');
        }
        return new Values($values);
    }
}
