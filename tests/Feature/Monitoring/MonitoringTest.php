<?php

namespace Folklore\Mediatheque\Tests\Feature\Monitoring;

use Carbon\CarbonImmutable;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Monitoring\FailureGroup;
use Folklore\Mediatheque\Monitoring\Monitoring;
use Folklore\Mediatheque\Monitoring\Period;
use Folklore\Mediatheque\Monitoring\RenditionDurations;
use Folklore\Mediatheque\Monitoring\RunFilters;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

class MonitoringTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-21 12:00:00');
    }

    private function monitoring(): Monitoring
    {
        return app(Monitoring::class);
    }

    /**
     * A run of a pipeline on a media, with one job per rendition.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, mixed>>  $jobs  Attributes of each job, by rendition
     */
    private function createRun(Media $media, PipelineStatus $status, array $attributes = [], array $jobs = ['h264' => []]): PipelineRun
    {
        $renditions = array_map(fn () => ['op' => 'video.transcode'], $jobs);
        $run = new PipelineRun;
        $run->forceFill([
            'name' => 'video',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'ffmpeg',
            'definition' => ['transcoder' => 'ffmpeg', 'renditions' => $renditions, 'main' => 'h264'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid,
            'visibility' => Visibility::Public,
            'status' => $status,
            ...$attributes,
        ])->save();

        foreach ($jobs as $name => $job) {
            (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => $name, ...$job])->save();
        }

        return $run;
    }

    private function hoursAgo(int|float $hours): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->subMinutes((int) round($hours * 60));
    }

    /**
     * @param  list<PipelineRunContract>  $runs
     * @return list<string>
     */
    private static function keys(array $runs): array
    {
        return array_map(fn (PipelineRunContract $run) => $run->key(), $runs);
    }

    #[Test]
    public function a_period_starts_before_it_ends(): void
    {
        $period = Period::lastHours(24);
        $this->assertSame(['2026-10-20 12:00:00', '2026-10-21 12:00:00'], [$period->from->toDateTimeString(), $period->to->toDateTimeString()]);

        $this->expectException(InvalidArgumentException::class);
        Period::between(Date::now(), Date::now()->subHour());
    }

    #[Test]
    public function the_overview_counts_media_and_runs(): void
    {
        $ready = $this->createMedia(['status' => MediaStatus::Ready]);
        $this->createMedia(['status' => MediaStatus::Ready]);
        $this->createMedia(['status' => MediaStatus::Failed]);
        $this->createMedia(['status' => MediaStatus::Ready])->delete();

        $this->createRun($ready, PipelineStatus::Running, ['started_at' => $this->hoursAgo(0.5)], ['h264' => ['status' => JobStatus::Running], 'poster' => ['status' => JobStatus::Queued, 'queued_at' => $this->hoursAgo(0.25)]]);
        $this->createRun($ready, PipelineStatus::Running, ['started_at' => $this->hoursAgo(3)]);
        $this->createRun($ready, PipelineStatus::Pending, ['created_at' => $this->hoursAgo(2)], ['h264' => ['status' => JobStatus::Queued, 'queued_at' => $this->hoursAgo(2)]]);
        $this->createRun($ready, PipelineStatus::Completed, ['ended_at' => $this->hoursAgo(1)]);
        $this->createRun($ready, PipelineStatus::Failed, ['ended_at' => $this->hoursAgo(5)]);
        $this->createRun($ready, PipelineStatus::Failed, ['ended_at' => $this->hoursAgo(30)]);

        $overview = $this->monitoring()->overview(Period::lastHours(24));

        $this->assertSame(['pending' => 0, 'processing' => 0, 'ready' => 2, 'failed' => 1], $overview->media);
        $this->assertSame([3, 2], [$overview->runsInProgress, $overview->stuckRuns], 'Running for 3 h, and pending for 2 h, are stuck.');
        $this->assertSame(['completed' => 1, 'failed' => 1, 'canceled' => 0], $overview->runsEnded);
        $this->assertSame('2026-10-21 10:00:00', $overview->oldestQueuedAt?->toDateTimeString());
    }

    #[Test]
    public function runs_are_listed_newest_first_with_their_filters(): void
    {
        $video = $this->createMedia(['type' => 'video']);
        $audio = $this->createMedia(['type' => 'audio']);
        $old = $this->createRun($video, PipelineStatus::Completed, ['created_at' => $this->hoursAgo(30)]);
        $failed = $this->createRun($video, PipelineStatus::Failed, ['error' => ['code' => 'timeout', 'message' => 'Too long']]);
        $onAudio = $this->createRun($audio, PipelineStatus::Completed, ['name' => 'audio', 'transcoder' => 'eclair']);
        $stuck = $this->createRun($audio, PipelineStatus::Running, ['name' => 'audio', 'started_at' => $this->hoursAgo(2)]);

        $keys = fn (RunFilters $filters) => array_map(fn (PipelineRunContract $run) => $run->key(), $this->monitoring()->runs($filters)->items);

        $this->assertSame([$stuck->uid, $onAudio->uid, $failed->uid, $old->uid], $keys(new RunFilters));
        $this->assertSame([$failed->uid], $keys(new RunFilters(status: PipelineStatus::Failed)));
        $this->assertSame([$stuck->uid, $onAudio->uid], $keys(new RunFilters(pipeline: 'audio')));
        $this->assertSame([$onAudio->uid], $keys(new RunFilters(transcoder: 'eclair')));
        $this->assertSame([$stuck->uid, $onAudio->uid], $keys(new RunFilters(mediaType: 'audio')));
        $this->assertSame([$failed->uid, $old->uid], $keys(new RunFilters(media: $video->uid)));
        $this->assertSame([$failed->uid], $keys(new RunFilters(errorCode: TranscoderErrorCode::Timeout)));
        $this->assertSame([$stuck->uid, $onAudio->uid, $failed->uid], $keys(new RunFilters(period: Period::lastHours(24))));
        $this->assertSame([$stuck->uid], $keys(new RunFilters(stuck: true)));
    }

    #[Test]
    public function runs_come_in_pages_with_their_jobs_and_media(): void
    {
        $media = $this->createMedia();
        $runs = [];
        foreach (range(1, 5) as $index) {
            $runs[] = $this->createRun($media, PipelineStatus::Completed)->uid;
        }

        $first = $this->monitoring()->runs(limit: 2);
        $second = $this->monitoring()->runs(cursor: $first->nextCursor, limit: 2);
        $last = $this->monitoring()->runs(cursor: $second->nextCursor, limit: 2);

        $this->assertSame(array_reverse($runs), [...self::keys($first->items), ...self::keys($second->items), ...self::keys($last->items)]);
        $this->assertNull($last->nextCursor);
        $this->assertSame($media->uid, $first->items[0]->media()->uid());
        $this->assertNotNull($first->items[0]->job('h264'));
    }

    #[Test]
    public function failures_are_grouped_by_code_pipeline_transcoder_and_rendition(): void
    {
        $media = $this->createMedia();
        $failed = fn (string $code, string $message, float $hours) => ['status' => JobStatus::Failed, 'error' => ['code' => $code, 'message' => $message], 'ended_at' => $this->hoursAgo($hours)];
        $this->createRun($media, PipelineStatus::Failed, [], ['h264' => $failed('timeout', 'First', 3), 'poster' => $failed('input_unreadable', 'Unreadable', 2)]);
        $this->createRun($media, PipelineStatus::Failed, [], ['h264' => $failed('timeout', 'Latest', 1)]);
        $this->createRun($media, PipelineStatus::Failed, [], ['h264' => $failed('timeout', 'Too old', 30)]);

        $failures = $this->monitoring()->failures(Period::lastHours(24));

        $this->assertSame([['timeout', 'h264', 2, 'Latest'], ['input_unreadable', 'poster', 1, 'Unreadable']], array_map(
            fn (FailureGroup $group) => [$group->code->value, $group->rendition, $group->count, $group->lastMessage],
            $failures,
        ));
        $this->assertSame(['video', 'ffmpeg', '2026-10-21 11:00:00'], [$failures[0]->pipeline, $failures[0]->transcoder, $failures[0]->lastFailedAt->toDateTimeString()]);
    }

    #[Test]
    public function durations_and_queue_waits_are_computed_by_rendition(): void
    {
        $media = $this->createMedia();
        foreach ([10, 20, 30, 40, 100] as $seconds) {
            $ended = $this->hoursAgo(1);
            $this->createRun($media, PipelineStatus::Completed, [], ['h264' => [
                'status' => JobStatus::Completed,
                'queued_at' => $ended->subSeconds($seconds + 5),
                'started_at' => $ended->subSeconds($seconds),
                'ended_at' => $ended,
            ]]);
        }
        $this->createRun($media, PipelineStatus::Completed, [], ['poster' => ['status' => JobStatus::Completed, 'started_at' => $this->hoursAgo(1.1), 'ended_at' => $this->hoursAgo(1)]]);

        $durations = $this->monitoring()->durations(Period::lastHours(24));

        $this->assertEquals([
            new RenditionDurations('video', 'h264', 'ffmpeg', 5, 30.0, 100.0, 5.0, 5.0),
            new RenditionDurations('video', 'poster', 'ffmpeg', 1, 360.0, 360.0, null, null),
        ], $durations);
    }

    #[Test]
    public function throughput_counts_runs_per_hour_and_bytes(): void
    {
        $media = $this->createMedia();
        $this->createFile($media, 'original', ['size' => 1000]);
        $this->createRun($media, PipelineStatus::Completed, ['ended_at' => '2026-10-21 10:10:00']);
        $this->createRun($media, PipelineStatus::Completed, ['ended_at' => '2026-10-21 10:50:00']);
        $run = $this->createRun($media, PipelineStatus::Failed, ['ended_at' => '2026-10-21 11:05:00']);
        $this->createFile($media, 'h264', ['size' => 400, 'pipeline_job_id' => $run->pipelineJobs()->value('id')]);

        $throughput = $this->monitoring()->throughput(Period::lastHours(24));

        $this->assertSame([
            '2026-10-21T10:00:00Z' => ['completed' => 2],
            '2026-10-21T11:00:00Z' => ['failed' => 1],
        ], $throughput->runsPerHour);
        $this->assertSame([1000, 400], [$throughput->bytesIngested, $throughput->bytesProduced]);
    }

    #[Test]
    public function the_monitoring_scope_narrows_media_runs_and_renditions(): void
    {
        $mine = $this->createMedia(['name' => 'Mine', 'status' => MediaStatus::Failed]);
        $theirs = $this->createMedia(['name' => 'Theirs', 'status' => MediaStatus::Failed]);
        $failed = ['h264' => ['status' => JobStatus::Failed, 'error' => ['code' => 'timeout', 'message' => 'x'], 'ended_at' => $this->hoursAgo(1)]];
        $this->createRun($mine, PipelineStatus::Failed, ['ended_at' => $this->hoursAgo(1)], $failed);
        $this->createRun($theirs, PipelineStatus::Failed, ['ended_at' => $this->hoursAgo(1)], $failed);

        Mediatheque::monitoringScope(fn (Builder $query, string $model) => $query->where('name', 'Mine'));

        $period = Period::lastHours(24);
        $overview = $this->monitoring()->overview($period);
        $this->assertSame([1, 1], [$overview->media['failed'], $overview->runsEnded['failed']]);
        $this->assertCount(1, $this->monitoring()->runs()->items);
        $this->assertSame($mine->uid, $this->monitoring()->runs()->items[0]->media()->uid());
        $this->assertSame(1, $this->monitoring()->failures($period)[0]->count);
    }
}
