<?php

namespace Folklore\Mediatheque\Tests\Unit\Metadata;

use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;
use Folklore\Mediatheque\Metadata\Waveform;
use Folklore\Mediatheque\Tests\TestCase;

class WaveformTest extends TestCase
{
    /**
     * Test getting a pipeline
     */
    public function test_get_value()
    {
        $metadata = new Waveform;
        $metadata->setName('waveform');
        $value = $metadata->getValue(public_path('test.wav'));
        $this->assertInstanceOf(ValueContract::class, $value);
        $this->assertEquals('json', $value->getType());
        $this->assertEquals('waveform', $value->getName());
        $this->assertIsArray($value->getValue());
        $this->assertContainsOnly('int', $value->getValue());
    }
}
