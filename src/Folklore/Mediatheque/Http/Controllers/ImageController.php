<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Folklore\Mediatheque\Contracts\Model\Image as ImageContract;

class ImageController extends ResourceController
{
    protected function getModel()
    {
        return app(ImageContract::class);
    }
}
