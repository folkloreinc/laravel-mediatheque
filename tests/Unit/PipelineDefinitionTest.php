<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PipelineDefinitionTest extends TestCase
{
    private function video(): PipelineDefinition
    {
        return PipelineDefinition::fromArray('video', [
            'transcoder' => 'eclair',
            'source' => 's3',
            'renditions' => [
                'normalized' => ['op' => 'video.transcode', 'codec' => 'h264', 'keep' => false],
                'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'from' => 'normalized'],
                'webm' => ['op' => 'video.transcode', 'codec' => 'vp9', 'from' => 'h264'],
                'poster' => ['op' => 'video.poster', 'role' => 'poster'],
                'thumbnails' => ['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail', 'from' => 'h264'],
            ],
        ]);
    }

    #[Test]
    public function it_reads_a_pipeline_from_the_config(): void
    {
        $pipeline = $this->video();

        $this->assertSame('video', $pipeline->name);
        $this->assertSame('eclair', $pipeline->transcoder);
        $this->assertSame('s3', $pipeline->source);
        $this->assertSame(['normalized', 'h264', 'webm', 'poster', 'thumbnails'], array_keys($pipeline->renditions));
        $this->assertSame('ffmpeg', PipelineDefinition::fromArray('audio', ['renditions' => []])->transcoder);
    }

    #[Test]
    public function a_run_is_closed_under_from(): void
    {
        $pipeline = $this->video();

        $this->assertSame(['normalized', 'h264', 'webm'], array_keys($pipeline->closeUnderFrom(['webm'])));
        $this->assertSame(['poster'], array_keys($pipeline->closeUnderFrom(['poster'])));
        $this->assertSame(['normalized', 'h264', 'poster', 'thumbnails'], array_keys($pipeline->closeUnderFrom(['thumbnails', 'poster'])));
        $this->assertSame(array_keys($pipeline->renditions), array_keys($pipeline->closeUnderFrom(array_keys($pipeline->renditions))));
    }

    #[Test]
    public function it_lists_the_renditions_that_read_from_one(): void
    {
        $pipeline = $this->video();

        $this->assertSame(['h264', 'webm', 'thumbnails'], $pipeline->dependentsOf('normalized'));
        $this->assertSame(['webm', 'thumbnails'], $pipeline->dependentsOf('h264'));
        $this->assertSame([], $pipeline->dependentsOf('poster'));
    }

    #[Test]
    public function the_main_rendition_is_the_first_kept_one_with_the_main_role(): void
    {
        $this->assertSame('h264', $this->video()->mainRendition()?->name);
        $this->assertNull(PipelineDefinition::fromArray('stills', ['renditions' => ['poster' => ['op' => 'video.poster', 'role' => 'poster']]])->mainRendition());
    }

    #[Test]
    public function it_refuses_a_rendition_that_reads_from_a_missing_one(): void
    {
        $this->expectException(InvalidPipeline::class);
        $this->expectExceptionMessage('reads from "h264", which is not one of its renditions');

        PipelineDefinition::fromArray('video', ['renditions' => ['webm' => ['op' => 'video.transcode', 'from' => 'h264']]]);
    }

    #[Test]
    public function it_refuses_a_cycle(): void
    {
        $this->expectException(InvalidPipeline::class);
        $this->expectExceptionMessage('cycle');

        PipelineDefinition::fromArray('video', ['renditions' => [
            'a' => ['op' => 'video.transcode', 'from' => 'b'],
            'b' => ['op' => 'video.transcode', 'from' => 'a'],
        ]]);
    }

    #[Test]
    public function it_refuses_an_unknown_rendition_in_a_run(): void
    {
        $this->expectException(InvalidPipeline::class);

        $this->video()->closeUnderFrom(['hls']);
    }

    #[Test]
    public function it_is_stored_as_json(): void
    {
        $pipeline = $this->video();
        $stored = json_decode((string) json_encode($pipeline->toArray()), true);
        $this->assertIsArray($stored);

        $this->assertEquals($pipeline, PipelineDefinition::fromArray('video', $stored));
    }
}
