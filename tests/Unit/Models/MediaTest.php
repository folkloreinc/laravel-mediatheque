<?php

namespace Folklore\Mediatheque\Tests\Unit\Models;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Tests\TestCase;

class MediaTest extends TestCase
{
    public function test_set_type_accepts_a_type_name()
    {
        $media = app(MediaContract::class);
        $media->setType('image');

        $this->assertEquals('image', $media->type);
    }

    public function test_set_type_accepts_a_type_object()
    {
        $media = app(MediaContract::class);
        $media->setType(mediatheque()->type('image'));

        $this->assertEquals('image', $media->type);
    }
}
