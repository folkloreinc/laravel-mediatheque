<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Folklore\Mediatheque\Contracts\Model\Audio as AudioContract;

class AudioController extends ResourceController
{
    protected function getModel()
    {
        return app(AudioContract::class);
    }
}
