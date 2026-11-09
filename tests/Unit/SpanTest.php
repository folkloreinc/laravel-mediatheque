<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Pipelines\Span;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SpanTest extends TestCase
{
    #[Test]
    public function a_transcode_keeps_the_span_of_its_start_and_duration(): void
    {
        $span = Span::of(Rendition::fromArray('preview', ['op' => 'video.transcode', 'start' => 5, 'duration' => 15.5]));

        $this->assertEquals(new Span(5.0, 15.5), $span);
        $this->assertFalse($span->isWhole());
    }

    #[Test]
    public function other_operations_and_invalid_options_keep_the_whole_input(): void
    {
        $this->assertTrue(Span::of(Rendition::fromArray('h264', ['op' => 'video.transcode']))->isWhole());
        $this->assertTrue(Span::of(Rendition::fromArray('mp3', ['op' => 'audio.transcode', 'duration' => 15]))->isWhole());
        $this->assertTrue(Span::of(Rendition::fromArray('h264', ['op' => 'video.transcode', 'start' => -1, 'duration' => 0]))->isWhole());
        $this->assertTrue(Span::of(Rendition::fromArray('h264', ['op' => 'video.transcode', 'duration' => '15']))->isWhole());
    }

    #[Test]
    public function its_length_is_cut_at_the_end_of_the_input(): void
    {
        $this->assertSame(60.0, (new Span)->length(60.0));
        $this->assertSame(40.0, (new Span(20.0))->length(60.0));
        $this->assertSame(15.0, (new Span(0.0, 15.0))->length(60.0));
        $this->assertSame(10.0, (new Span(50.0, 15.0))->length(60.0));
        $this->assertSame(0.0, (new Span(70.0, 15.0))->length(60.0));
        $this->assertSame(15.0, (new Span(0.0, 15.0))->length(null), 'Without the input\'s duration, the span\'s.');
        $this->assertNull((new Span)->length(null));
    }
}
