<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Folklore\Mediatheque\Contracts\Model\Video as VideoContract;

class VideoController extends ResourceController
{
    protected function getModel()
    {
        return app(VideoContract::class);
    }
}
