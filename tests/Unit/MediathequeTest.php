<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;
use Folklore\Mediatheque\Mediatheque;
use Folklore\Mediatheque\Tests\TestCase;

class MediathequeTest extends TestCase
{
    /**
     * Test getting a pipeline
     */
    public function test_pipeline()
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
     */
    public function test_has_pipeline()
    {
        $mediatheque = new Mediatheque(
            app(),
            app('mediatheque.types'),
            app('mediatheque.pipelines')
        );
        $this->assertTrue($mediatheque->hasPipeline('video'));
    }
}
