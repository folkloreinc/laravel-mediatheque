<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\Mime as MimeService;
use Folklore\Mediatheque\Contracts\Services\FontFamilyName as FontFamilyNameService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class FontFamilyName extends Reader
{
    protected $name = 'font_family_name';

    public function getValue($path): ?ValueContract
    {
        $mime = app(MimeService::class)->getMime($path);
        if (is_null($mime)) {
            return null;
        }
        $value = null;
        if (!preg_match('/^(audio|video|image)\//i', $mime)) {
            $value = app(FontFamilyNameService::class)->getFontFamilyName(
                $path
            );
        }
        return isset($value)
            ? new Value($this->getName(), $value, 'string')
            : null;
    }
}
