<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\MediaDeleted;
use Folklore\Mediatheque\Events\MediaUpdated;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\MediaContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class EloquentModelsTest extends DatabaseTestCase
{
    use CreatesMedia;
    use MediaContractTests;

    protected function mediaWithFiles(string $type, Visibility $visibility, array $files, array $metadata = []): MediaContract
    {
        $media = $this->createMedia(['type' => $type, 'visibility' => $visibility, 'metadata' => $metadata]);

        foreach ($files as [$handle, $index, $role]) {
            $this->createFile($media, $handle, ['index' => $index, 'role' => $role]);
        }

        $fresh = Media::query()->findOrFail($media->id);

        return $fresh;
    }

    #[Test]
    public function a_media_gets_a_prefixed_uid_and_defaults(): void
    {
        config(['mediatheque.ids.media_prefix' => 'asset']);

        $media = $this->createMedia(['status' => MediaStatus::Pending]);

        $this->assertTrue(Uid::isValid($media->uid(), 'asset'));
        $this->assertSame('uid', $media->getRouteKeyName());
        $this->assertSame(Visibility::Public, $media->fresh()?->visibility());
        $this->assertSame([], $media->metadata());
    }

    #[Test]
    public function a_uid_set_before_saving_is_kept(): void
    {
        $uid = Uid::generate('med');

        $this->assertSame($uid, $this->createMedia(['uid' => $uid])->uid());
    }

    #[Test]
    public function tables_have_the_configured_prefix(): void
    {
        $this->assertSame('mediatheque_medias', (new Media)->getTable());
        $this->assertSame('mediatheque_files', Models::table('files'));

        config(['mediatheque.table_prefix' => 'media_']);

        $this->assertSame('media_pipelines_jobs', (new PipelineJob)->getTable());
    }

    #[Test]
    public function model_lifecycle_events_carry_the_media(): void
    {
        $media = $this->createMedia();
        Event::fake([MediaUpdated::class, MediaDeleted::class]);

        $media->forceFill(['name' => 'Renamed'])->save();
        $media->delete();

        Event::assertDispatched(MediaUpdated::class, fn (MediaUpdated $event) => $event->media->uid() === $media->uid());
        Event::assertDispatched(MediaDeleted::class);
        $this->assertSoftDeleted($media);
    }

    #[Test]
    public function the_model_classes_come_from_the_config(): void
    {
        config(['mediatheque.models.media' => TenantMedia::class]);
        $media = TenantMedia::query()->getModel()->newInstance();
        $media->forceFill(['type' => 'image'])->save();
        $file = $this->createFile($media);

        $this->assertInstanceOf(TenantMedia::class, $file->media);
        $this->assertSame(TenantMedia::class, Models::media());
    }

    #[Test]
    public function a_run_reads_its_renditions_from_its_snapshot_and_its_media_without_global_scopes(): void
    {
        config(['mediatheque.models.media' => TenantMedia::class]);
        $media = new TenantMedia;
        $media->forceFill(['type' => 'video', 'status' => MediaStatus::Processing])->save();
        $this->createFile($media);

        $pipeline = PipelineDefinition::fromArray('video', ['renditions' => [
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264'],
            'webm' => ['op' => 'video.transcode', 'codec' => 'vp9', 'from' => 'h264'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster'],
        ]]);
        $renditions = $pipeline->closeUnderFrom(['webm']);

        $run = new PipelineRun;
        $run->forceFill([
            'name' => 'video',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'ffmpeg',
            'definition' => PipelineRun::snapshot($pipeline, $renditions),
            'output_source' => 'public',
            'path_prefix' => $media->uid.'/run',
            'visibility' => Visibility::Public,
        ])->save();
        foreach (array_keys($renditions) as $name) {
            $run->pipelineJobs()->forceCreate(['name' => $name]);
        }

        TenantMedia::$scoped = true;
        $run = PipelineRun::query()->findOrFail($run->id);

        $this->assertTrue(Uid::isValid($run->key(), 'run'));
        $this->assertSame(['h264', 'webm'], array_keys($run->renditions()));
        $this->assertSame('h264', $run->mainRendition());
        $this->assertSame($media->uid, $run->media()->uid());
        $this->assertSame('original', $run->input()->handle());
        $this->assertSame(PipelineStatus::Pending, $run->status());
        $webm = $run->job('webm');
        $this->assertNotNull($webm);
        $this->assertSame(JobStatus::Queued, $webm->status());
        $this->assertSame('h264', $webm->rendition()->from);
        $this->assertSame($run->key(), $webm->run()->key());
        $this->assertNull($run->job('poster'));
        $this->assertCount(2, $run->jobs());
        $this->assertSame(0.0, $run->progress());
        $this->assertNull(TenantMedia::query()->find($media->id), 'The application scope hides it from the application.');
    }

    #[Test]
    public function errors_are_stored_as_code_and_message(): void
    {
        $job = new PipelineJob;
        $job->forceFill(['error' => (new TranscoderError(TranscoderErrorCode::Timeout, 'Too long'))->toArray()]);

        $this->assertSame(TranscoderErrorCode::Timeout, $job->error()?->code);
        $this->assertSame('Too long', $job->error()->message);
    }
}
