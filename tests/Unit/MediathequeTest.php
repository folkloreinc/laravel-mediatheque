<?php

use Folklore\Mediatheque\Mediatheque;
use Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;

/**
 * @coversDefaultClass Folklore\Mediatheque\Mediatheque
 */
class MediathequeTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->mediatheque = new Mediatheque(app());
    }

    /**
     * Test add pipeline
     *
     * @test
     * @covers ::addPipeline
     * @covers ::pipeline
     */
    public function testAddPipeline()
    {
        $pipelineOptions = [
            'queue' => true,
            'autostart' => false,
            'jobs' => [
                'test'
            ],
        ];
        $this->mediatheque->addPipeline('test', $pipelineOptions);

        $pipeline = $this->mediatheque->pipeline('test');
        $options = $pipeline->getOptions();
        $jobs = $pipeline->getJobs();
        $this->assertInstanceOf(PipelineContract::class, $pipeline);
        $this->assertEquals($pipelineOptions['queue'], $options['queue']);
        $this->assertEquals($pipelineOptions['autostart'], $options['autostart']);
        $this->assertArrayNotHasKey('jobs', $options);
        $this->assertEquals($pipelineOptions['jobs'], $jobs);
    }

    /**
     * Test add pipeline class
     *
     * @test
     * @covers ::addPipeline
     * @covers ::pipeline
     */
    public function testAddPipelineClass()
    {
        $this->mediatheque->addPipeline('test', PipelineTest::class);
        $pipeline = $this->mediatheque->pipeline('test');
        $this->assertInstanceOf(PipelineTest::class, $pipeline);
    }

    /**
     * Test add pipeline
     *
     * @test
     * @covers ::addPipeline
     * @covers ::pipeline
     */
    public function testAddPipelineInstance()
    {
        $pipeline = app(PipelineContract::class);
        $this->mediatheque->addPipeline('test', $pipeline);
        $this->assertEquals($pipeline, $this->mediatheque->pipeline('test'));
    }

    /**
     * Test set pipelines
     *
     * @test
     * @covers ::setPipelines
     * @covers ::pipeline
     */
    public function testSetPipelines()
    {
        $pipelineInstance = app(PipelineContract::class);
        $this->mediatheque->setPipelines([
            'test_instance' => $pipelineInstance,
            'test_class' => PipelineTest::class,
            'test_array' => [
                'queue' => true,
                'autostart' => false,
                'jobs' => [
                    'test'
                ],
            ],
        ]);
        $this->assertEquals($pipelineInstance, $this->mediatheque->pipeline('test_instance'));
        $this->assertInstanceOf(PipelineTest::class, $this->mediatheque->pipeline('test_class'));
        $this->assertInstanceOf(PipelineContract::class, $this->mediatheque->pipeline('test_array'));
    }
}
