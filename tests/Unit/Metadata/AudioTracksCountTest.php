<?php

namespace Folklore\Mediatheque\Tests\Unit\Metadata;

use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;
use Folklore\Mediatheque\Metadata\AudioTracksCount;
use Folklore\Mediatheque\Tests\TestCase;

class AudioTracksCountTest extends TestCase
{
    /**
     * Test getting a pipeline
     */
    public function test_get_value()
    {
        $metadata = new AudioTracksCount;
        $metadata->setName('audio_tracks_count');
        $value = $metadata->getValue(public_path('test.mp4'));
        $this->assertInstanceOf(ValueContract::class, $value);
        $this->assertEquals('integer', $value->getType());
        $this->assertEquals('audio_tracks_count', $value->getName());
        $this->assertEquals(1, $value->getValue());
    }

    /**
     * Test getting a pipeline
     */
    public function test_get_value_invalid()
    {
        $metadata = new AudioTracksCount;
        $metadata->setName('audio_tracks_count');
        $value = $metadata->getValue(public_path('font.otf'));
        $this->assertNull($value);
    }
}
