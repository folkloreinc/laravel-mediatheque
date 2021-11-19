<?php

namespace Folklore\Mediatheque\Tests\Unit\Metadata;

use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\Metadata\Waveform;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

/**
 * @coversDefaultClass Folklore\Mediatheque\Metadata\Waveform
 */
class WaveformTest extends TestCase
{
    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::handle
     */
    public function testGetValue()
    {
        $metadata = new Waveform();
        $metadata->setName('waveform');
        $value = $metadata->getValue(public_path('test.wav'));
        $this->assertInstanceOf(ValueContract::class, $value);
        $this->assertEquals('json', $value->getType());
        $this->assertEquals('waveform', $value->getName());
        $this->assertIsArray($value->getValue());
        $this->assertContainsOnly('int', $value->getValue());
    }
}
