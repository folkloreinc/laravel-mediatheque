<?php

namespace Folklore\Mediatheque\Services;

use Contao\ImagineSvg\Imagine;
use Contao\ImagineSvg\SvgBox;
use Folklore\Mediatheque\Contracts\Services\Svg;

class ImagineSvg implements Svg
{
    protected $imagine;

    public function __construct()
    {
        $this->imagine = new Imagine();
    }

    /**
     * Get the dimension of a path
     * @param  string $path The path of a file
     * @return array The dimension
     */
    public function getDimension(string $path): ?array
    {
        $size = $this->imagine->open($path)->getSize();

        if (SvgBox::TYPE_NONE === $size->getType()) {
            return null;
        }

        return [$size->getWidth(), $size->getHeight()];
    }
}
