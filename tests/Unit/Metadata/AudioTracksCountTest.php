<?php

namespace Folklore\Mediatheque\Tests\Unit\Metadata;

use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\Metadata\AudioTracksCount;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

/**
 * @coversDefaultClass Folklore\Mediatheque\Metadata\AudioTracksCount
 */
class AudioTracksCountTest extends TestCase
{
    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::handle
     */
    public function testGetValue()
    {
        $metadata = new AudioTracksCount();
        $metadata->setName('audio_tracks_count');
        $value = $metadata->getValue(public_path('test.mp4'));
        $this->assertInstanceOf(ValueContract::class, $value);
        $this->assertEquals('integer', $value->getType());
        $this->assertEquals('audio_tracks_count', $value->getName());
        $this->assertEquals(1, $value->getValue());
    }

    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::handle
     */
    public function testGetValueInvalid()
    {
        $metadata = new AudioTracksCount();
        $metadata->setName('audio_tracks_count');
        $value = $metadata->getValue(public_path('font.otf'));
        $this->assertNull($value);
    }
}
