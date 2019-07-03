<?php

use Folklore\Mediatheque\Mediatheque;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;

/**
 * @coversDefaultClass Folklore\Mediatheque\Mediatheque
 */
class MediathequeTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->mediatheque = new Mediatheque(
            app(),
            app('mediatheque.types'),
            app('mediatheque.pipelines')
        );
    }

    /**
     * Test getting a pipeline
     *
     * @test
     * @covers ::pipeline
     */
    public function testPipeline()
    {
        $pipeline = $this->mediatheque->pipeline('video');
        $this->assertInstanceOf(PipelineContract::class, $pipeline);
        $this->assertEquals('video', $pipeline->getName());
        $this->assertEquals(config('mediatheque.pipelines.video.jobs'), $pipeline->getJobs());
    }

    /**
     * Test add pipeline class
     *
     * @test
     * @covers ::hasPipeline
     */
    public function testHasPipeline()
    {
        $this->assertTrue($this->mediatheque->hasPipeline('video'));
    }
}
