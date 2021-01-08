<?php

use Folklore\Mediatheque\Mediatheque;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;

/**
 * @coversDefaultClass Folklore\Mediatheque\Mediatheque
 */
class MediathequeTest extends TestCase
{
    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::pipeline
     */
    public function testPipeline()
    {
        $mediatheque = new Mediatheque(
            app(),
            app('mediatheque.types'),
            app('mediatheque.pipelines')
        );
        $pipeline = $mediatheque->pipeline('video');
        $this->assertInstanceOf(PipelineContract::class, $pipeline);
        $this->assertEquals('video', $pipeline->name());
        $this->assertEquals(config('mediatheque.pipelines.video.jobs'), $pipeline->jobs()->toArray());
    }

    /**
     * Test add pipeline class
     *
     * @test
     * @covers ::hasPipeline
     */
    public function testHasPipeline()
    {
        $mediatheque = new Mediatheque(
            app(),
            app('mediatheque.types'),
            app('mediatheque.pipelines')
        );
        $this->assertTrue($mediatheque->hasPipeline('video'));
    }
}
