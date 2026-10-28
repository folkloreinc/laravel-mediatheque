<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

class PruneCommandTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A media with its original and `h264` stored on the disk, and a run.
     */
    private function storedMedia(): Media
    {
        $media = $this->createMedia();
        $run = $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(40));
        foreach (['original', 'h264'] as $handle) {
            $file = $this->createFile($media, $handle, $handle === 'h264' ? ['pipeline_job_id' => $run->pipelineJobs()->value('id')] : []);
            Storage::disk('public')->put((string) $file->path, $handle);
        }

        return $media;
    }

    private function createRun(Media $media, string $pipeline, PipelineStatus $status, ?\DateTimeInterface $endedAt): PipelineRun
    {
        $run = new PipelineRun;
        $run->forceFill([
            'name' => $pipeline,
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'ffmpeg',
            'definition' => ['transcoder' => 'ffmpeg', 'renditions' => ['h264' => ['op' => 'video.transcode']], 'main' => 'h264'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid,
            'visibility' => Visibility::Public,
            'status' => $status,
            'ended_at' => $endedAt,
        ])->save();
        (new PipelineJob)->forceFill([
            'pipeline_id' => $run->id,
            'name' => 'h264',
            'status' => $status === PipelineStatus::Completed ? JobStatus::Completed : JobStatus::Running,
        ])->save();

        return $run;
    }

    private function deleteDaysAgo(Media $media, int $days): void
    {
        $media->delete();
        $media->forceFill(['deleted_at' => Date::now()->subDays($days)])->save();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function prune(array $options = []): PendingCommand
    {
        $command = $this->artisan('mediatheque:prune', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    #[Test]
    public function media_deleted_before_the_grace_period_are_force_deleted_with_their_files(): void
    {
        $old = $this->storedMedia();
        $recent = $this->storedMedia();
        $live = $this->storedMedia();
        $this->deleteDaysAgo($old, 31);
        $this->deleteDaysAgo($recent, 5);
        $oldPaths = array_filter(File::query()->where('media_id', $old->id)->pluck('path')->all(), 'is_string');

        $this->prune()->expectsOutputToContain('Deleted media deleted more than 30 days ago')->assertSuccessful();

        $this->assertNull(Media::withTrashed()->find($old->id));
        $this->assertSame(0, File::query()->where('media_id', $old->id)->count());
        $this->assertSame(0, PipelineRun::query()->where('pipelinable_id', $old->id)->count());
        $this->assertSame(0, PipelineJob::query()->whereNotIn('pipeline_id', PipelineRun::query()->select('id'))->count());
        foreach ($oldPaths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertNotNull(Media::withTrashed()->find($recent->id));
        $this->assertNotNull(Media::query()->find($live->id));
        $this->assertSame(4, File::query()->count());
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function force_deletion_deletes_the_stored_files_in_a_queued_job_after_the_commit(): void
    {
        Bus::fake();
        $media = $this->storedMedia();
        $paths = File::query()->where('media_id', $media->id)->orderBy('path')->pluck('path')->all();

        DB::transaction(function () use ($media) {
            $media->forceDelete();
        });

        Bus::assertDispatched(DeleteStoredFiles::class, fn (DeleteStoredFiles $job) => $job->disk === 'public' && collect($job->paths)->sort()->values()->all() === $paths && $job->afterCommit === true);
        $this->assertNull(Media::withTrashed()->find($media->id));
        $this->assertSame(0, PipelineRun::query()->count());
    }

    #[Test]
    public function ended_runs_are_deleted_except_the_last_of_each_pipeline(): void
    {
        $media = $this->createMedia();
        $other = $this->createMedia();
        $oldest = $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(20));
        $last = $this->createRun($media, 'video', PipelineStatus::Failed, Date::now()->subDays(10));
        $probe = $this->createRun($media, 'probe', PipelineStatus::Completed, Date::now()->subDays(30));
        $otherOld = $this->createRun($other, 'video', PipelineStatus::Canceled, Date::now()->subDays(12));
        $otherRecent = $this->createRun($other, 'video', PipelineStatus::Completed, Date::now()->subDays(3));
        $otherRecentest = $this->createRun($other, 'video', PipelineStatus::Running, null);
        $file = $this->createFile($media, 'h264', ['pipeline_job_id' => $oldest->pipelineJobs()->value('id')]);

        $this->prune()->expectsOutputToContain('Deleted runs ended more than 7 days ago')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$last->id, $probe->id, $otherRecent->id, $otherRecentest->id],
            PipelineRun::query()->pluck('id')->all(),
        );
        $this->assertNull($otherOld->fresh());
        $this->assertSame(0, PipelineJob::query()->where('pipeline_id', $oldest->id)->count());
        $this->assertNull($file->fresh()?->pipeline_job_id, 'The file stays, without its pruned job.');
        $this->assertNotNull($file->fresh());
    }

    #[Test]
    public function a_dry_run_lists_and_deletes_nothing(): void
    {
        $media = $this->storedMedia();
        $this->deleteDaysAgo($media, 31);
        $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(20));

        $this->prune(['--dry-run' => true])
            ->expectsOutputToContain($media->uid)
            ->expectsOutputToContain('Would delete media deleted more than 30 days ago')
            ->assertSuccessful();

        $this->assertNotNull(Media::withTrashed()->find($media->id));
        $this->assertSame(2, PipelineRun::query()->count());
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function running_it_again_deletes_nothing_more(): void
    {
        $media = $this->storedMedia();
        $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(20));
        $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(10));
        $this->prune()->assertSuccessful();

        $this->prune()->assertSuccessful();

        $this->assertSame(1, PipelineRun::query()->count());
        $this->assertNotNull($media->fresh());
    }

    #[Test]
    public function null_settings_leave_media_and_runs(): void
    {
        config(['mediatheque.prune.after_days' => null, 'mediatheque.prune.runs_after_days' => null]);
        $media = $this->storedMedia();
        $this->createRun($media, 'video', PipelineStatus::Completed, Date::now()->subDays(20));
        $this->deleteDaysAgo($media, 100);

        $this->prune()->expectsOutputToContain('off')->assertSuccessful();

        $this->assertNotNull(Media::withTrashed()->find($media->id));
        $this->assertSame(2, PipelineRun::query()->count());
    }

    #[Test]
    public function the_grace_periods_come_from_the_config(): void
    {
        config(['mediatheque.prune.after_days' => 3, 'mediatheque.prune.runs_after_days' => 30]);
        $media = $this->storedMedia();
        $this->deleteDaysAgo($media, 5);
        $kept = $this->createMedia();
        $this->createRun($kept, 'video', PipelineStatus::Completed, Date::now()->subDays(20));
        $this->createRun($kept, 'video', PipelineStatus::Completed, Date::now()->subDays(10));

        $this->prune()->assertSuccessful();

        $this->assertNull(Media::withTrashed()->find($media->id));
        $this->assertSame(2, PipelineRun::query()->where('pipelinable_id', $kept->id)->count());
    }
}
