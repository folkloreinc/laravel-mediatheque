<?php

namespace Folklore\Mediatheque\Types;

use Folklore\Mediatheque\Contracts\Services\AnimatedImage;
use Folklore\Mediatheque\Support\Type;

class Video extends Type
{
    protected $animatedImage = false;

    public function pathIsType(string $path): bool
    {
        $pathIsType = parent::pathIsType($path);
        $animatedImage = $this->get('animatedImage');
        if (! $pathIsType && $animatedImage && resolve(AnimatedImage::class)->isAnimated($path)) {
            return true;
        }

        return $pathIsType;
    }
}
