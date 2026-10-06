<?php

namespace Folklore\Mediatheque\Tests\Unit\Services;

use Folklore\Mediatheque\Services\ImagineSvg;
use Folklore\Mediatheque\Tests\TestCase;

class ImagineSvgTest extends TestCase
{
    /**
     * Test getting a pipeline
     */
    public function test_get_dimension()
    {
        $service = new ImagineSvg;
        $dimension = $service->getDimension(public_path('image.svg'));
        $this->assertIsArray($dimension);
        $this->assertEquals($dimension[0], $dimension[1]);
    }
}
