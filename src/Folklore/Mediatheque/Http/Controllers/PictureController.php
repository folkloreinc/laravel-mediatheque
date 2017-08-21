<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Folklore\Mediatheque\Contracts\Models\Picture as PictureContract;

class PictureController extends ResourceController
{
    protected function getModel()
    {
        return app(PictureContract::class);
    }
}
