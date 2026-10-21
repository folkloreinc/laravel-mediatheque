<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

class StatusCommandTest extends DatabaseTestCase
{
    use CreatesMedia;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $job
     */
    private function createRun(Media $media, PipelineStatus $status, array $attributes = [], array $job = []): PipelineRun
    {
        $run = new PipelineRun;
        $run->forceFill([
            'name' => 'video',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'ffmpeg',
            'definition' => ['transcoder' => 'ffmpeg', 'renditions' => ['h264' => ['op' => 'video.transcode']], 'main' => 'h264'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid,
            'visibility' => Visibility::Public,
            'status' => $status,
            ...$attributes,
        ])->save();
        (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => 'h264', ...$job])->save();

        return $run;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function statusCommand(array $options = []): PendingCommand
    {
        $command = $this->artisan('mediatheque:status', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    #[Test]
    public function it_shows_the_overview_stuck_runs_and_failures(): void
    {
        $media = $this->createMedia(['status' => MediaStatus::Ready]);
        $stuck = $this->createRun($media, PipelineStatus::Running, ['started_at' => Date::now()->subHours(3)], ['status' => JobStatus::Running]);
        $this->createRun($media, PipelineStatus::Failed, ['ended_at' => Date::now()->subHour()], [
            'status' => JobStatus::Failed,
            'error' => ['code' => 'timeout', 'message' => 'ffmpeg took too long'],
            'ended_at' => Date::now()->subHour(),
        ]);

        $this->statusCommand()
            ->expectsOutputToContain('Stuck runs')
            ->expectsOutputToContain($stuck->uid)
            ->expectsOutputToContain('ffmpeg took too long')
            ->assertSuccessful();
    }

    #[Test]
    public function a_quiet_period_lists_no_stuck_run_or_failure(): void
    {
        $this->createMedia(['status' => MediaStatus::Ready]);

        $this->statusCommand(['--hours' => 2])
            ->doesntExpectOutputToContain('Stuck runs')
            ->doesntExpectOutputToContain('Failures in the last')
            ->assertSuccessful();
    }

    #[Test]
    public function its_options_take_positive_integers(): void
    {
        $this->statusCommand(['--hours' => 'all'])->assertFailed();
        $this->statusCommand(['--hours' => 0])->assertFailed();
    }
}
