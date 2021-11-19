<?php

namespace Folklore\Mediatheque\Tests\Unit\Services;

use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\Services\ImagineSvg;

/**
 * @coversDefaultClass Folklore\Mediatheque\Services\ImagineSvg
 */
class ImagineSvgTest extends TestCase
{
    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::getDimension
     */
    public function testGetDimension()
    {
        $service = new ImagineSvg();
        $dimension = $service->getDimension(public_path('image.svg'));
        $this->assertIsArray($dimension);
        $this->assertEquals($dimension[0], $dimension[1]);
    }
}
