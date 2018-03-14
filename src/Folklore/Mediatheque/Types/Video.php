<?php

namespace Folklore\Mediatheque\Types;

use Folklore\Mediatheque\Support\Type;

class Video extends Type
{
    protected function model()
    {
        return \Folklore\Mediatheque\Models\Video::class;
    }

    protected function pipeline()
    {
        return 'video';
    }

    protected function mimes()
    {
        return [
            'video/*' => '*',
            'video/quicktime' => 'mov',
            'video/mpeg' => 'mp4',
            'video/mpeg-4' => 'mp4',
            'video/x-m4v' => 'mp4'
        ];
    }
}
