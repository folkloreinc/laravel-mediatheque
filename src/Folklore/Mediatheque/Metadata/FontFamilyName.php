<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\FontFamilyName as FontFamilyNameService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class FontFamilyName extends Reader
{
    protected $name = 'font_family_name';

    public function getValue(string $path): ?ValueContract
    {
        $value = app(FontFamilyNameService::class)->getFontFamilyName(
            $path
        );
        return !is_null($value)
            ? new Value($this->getName(), $value, 'string')
            : null;
    }
}
