<?php

namespace Folklore\Mediatheque\Tests\Feature\Pipelines;

use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class PipelineRunnerTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([RunRendition::class]);
    }

    private function video(): Media
    {
        $media = $this->createMedia(['type' => 'video', 'status' => MediaStatus::Processing]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4']);

        return $media;
    }

    #[Test]
    public function the_ffmpeg_driver_dispatches_the_renditions_that_read_from_the_original(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'queue' => 'videos'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster', 'from' => 'h264'],
        ]]);

        $run = app(PipelineRunner::class)->run($this->video());

        $this->assertNotNull($run);
        $this->assertNull($run->externalId());
        Bus::assertDispatchedTimes(RunRendition::class, 1);
        Bus::assertDispatched(RunRendition::class, fn (RunRendition $job) => $job->rendition === 'h264' && $job->queue === 'videos');
    }

    #[Test]
    public function only_the_renditions_asked_for_run_with_those_they_read_from(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster', 'from' => 'h264'],
            'thumbnails' => ['op' => 'video.thumbnails', 'role' => 'thumbnail'],
        ]]);

        $run = app(PipelineRunner::class)->run($this->video(), renditions: ['poster']);

        $this->assertSame(['h264', 'poster'], array_keys($run?->renditions() ?? []));
    }

    #[Test]
    public function an_operation_the_transcoder_does_not_support_throws_before_anything_is_created(): void
    {
        config(['mediatheque.pipelines.video.renditions' => ['hls' => ['op' => 'video.hls']]]);

        $this->expectException(InvalidPipeline::class);
        $this->expectExceptionMessage('video.hls');

        app(PipelineRunner::class)->run($this->video());
    }

    #[Test]
    public function a_type_without_a_pipeline_has_nothing_to_run(): void
    {
        $font = $this->createMedia(['type' => 'font']);
        $this->createFile($font);

        $this->expectException(InvalidArgumentException::class);

        app(PipelineRunner::class)->run($font);
    }
}
