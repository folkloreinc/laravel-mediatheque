<?php

namespace Folklore\Mediatheque\Http\Controllers;

use Folklore\Mediatheque\Contracts\Models\Font as FontContract;

class FontController extends ResourceController
{
    protected function getModel()
    {
        return app(FontContract::class);
    }
}
