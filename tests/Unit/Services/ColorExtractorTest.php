<?php

namespace Folklore\Mediatheque\Tests\Unit\Services;

use Folklore\Mediatheque\Services\ColorExtractor;
use Folklore\Mediatheque\Tests\TestCase;

class ColorExtractorTest extends TestCase
{
    public function test_get_colors()
    {
        $service = new ColorExtractor;
        $colors = $service->getColors(public_path('image.jpg'), 3);
        $this->assertIsArray($colors);
        $this->assertNotEmpty($colors);
    }

    public function test_get_palette()
    {
        $service = new ColorExtractor;
        $palette = $service->getPalette(public_path('image.jpg'), 3);
        $this->assertIsArray($palette);
        $this->assertNotEmpty($palette);
    }
}
