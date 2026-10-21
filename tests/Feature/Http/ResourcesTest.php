<?php

namespace Folklore\Mediatheque\Tests\Feature\Http;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Http\Resources\PipelineRunResource;
use Folklore\Mediatheque\Http\Resources\UploadResource;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

class ResourcesTest extends DatabaseTestCase
{
    use CreatesMedia;

    /**
     * A resource as a client receives it, through JSON.
     *
     * @return array<mixed>
     */
    private static function rendered(PipelineRunResource|UploadResource $resource): array
    {
        $json = json_decode((string) json_encode($resource->toArray(new Request)), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<mixed>
     */
    private static function fixture(string $name): array
    {
        $json = json_decode((string) file_get_contents(self::fixturePath('json/'.$name.'.json')), true);

        return is_array($json) ? $json : [];
    }

    #[Test]
    public function a_run_renders_its_renditions_media_and_durations(): void
    {
        $this->travelTo('2026-10-21 14:00:00');
        $media = $this->createMedia(['uid' => 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', 'name' => 'Episode 1', 'status' => MediaStatus::Ready]);
        $at = fn (string $time) => Date::parse('2026-10-21 '.$time);
        $timeout = ['code' => 'timeout', 'message' => 'The poster took too long.'];

        $run = new PipelineRun;
        $run->forceFill([
            'uid' => 'run_01JAB3QX5Z8Y7W6V5T4S3R2Q1P',
            'name' => 'video',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'ffmpeg',
            'definition' => ['transcoder' => 'ffmpeg', 'renditions' => [
                'h264' => ['op' => 'video.transcode', 'codec' => 'h264'],
                'poster' => ['op' => 'video.poster', 'role' => 'poster'],
                'thumbnails' => ['op' => 'video.thumbnails', 'from' => 'h264', 'role' => 'thumbnail'],
            ], 'main' => 'h264'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid.'/run',
            'visibility' => Visibility::Public,
            'status' => PipelineStatus::Failed,
            'error' => $timeout,
            'started_at' => $at('14:00:05'),
            'ended_at' => $at('14:02:06'),
        ])->save();
        foreach ([
            'h264' => ['status' => JobStatus::Completed, 'progress' => 1.0, 'attempts' => 1, 'queued_at' => $at('14:00:00'), 'started_at' => $at('14:00:05'), 'ended_at' => $at('14:02:05')],
            'poster' => ['status' => JobStatus::Failed, 'attempts' => 3, 'error' => $timeout, 'queued_at' => $at('14:00:00'), 'started_at' => $at('14:00:05'), 'ended_at' => $at('14:01:00')],
            'thumbnails' => ['status' => JobStatus::Skipped, 'reason' => 'The input doesn\'t match the rendition\'s condition.', 'queued_at' => $at('14:02:05'), 'ended_at' => $at('14:02:06')],
        ] as $name => $attributes) {
            (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => $name, ...$attributes])->save();
        }

        $json = self::rendered(new PipelineRunResource($run->load('pipelineJobs')));

        // JSON writes 5.0 as 5: numbers compare by value, keys in order
        $this->assertEquals(self::fixture('pipeline-run'), $json);
        $this->assertSame(array_keys(self::fixture('pipeline-run')), array_keys($json));
    }

    #[Test]
    public function an_upload_renders_how_it_ended_and_never_where_it_is_stored(): void
    {
        $this->travelTo('2026-10-21 14:00:00');
        $upload = new Upload;
        $upload->forceFill([
            'uid' => 'upl_01JAB3QX5Z8Y7W6V5T4S3R2Q1P',
            'disk' => 's3',
            'path' => 'mediatheque-uploads/upl_01JAB3QX5Z8Y7W6V5T4S3R2Q1P',
            'provider_upload_id' => 'secret-multipart-id',
            'filename' => 'photo.jpg',
            'size' => 52000,
            'expires_at' => Date::now()->addDay(),
        ])->save();
        $this->travelTo('2026-10-21 14:05:00');
        $upload->markAborted('unknown_type');

        $json = self::rendered(new UploadResource($upload->refresh()));

        $this->assertSame(self::fixture('upload'), $json);
        $this->assertStringNotContainsString('secret-multipart-id', (string) json_encode($json));
    }

    #[Test]
    public function a_completed_upload_names_its_media(): void
    {
        $media = $this->createMedia();
        $upload = new Upload;
        $upload->forceFill(['disk' => 's3', 'path' => 'x', 'expires_at' => Date::now()->addDay()])->save();
        $upload->markCompleted($media->id);

        $this->assertSame($media->uid, self::rendered(new UploadResource($upload->refresh()))['media']);
        $this->assertSame(UploadStatus::Completed->value, self::rendered(new UploadResource($upload))['status']);
    }

    #[Test]
    public function a_run_of_the_fake_renders_the_same_shape(): void
    {
        $media = Mediatheque::fake()->ingest(UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'));
        $run = Mediatheque::fake()->runs($media)->first();
        $this->assertNotNull($run);

        $json = self::rendered(new PipelineRunResource($run));

        $this->assertSame(array_keys(self::fixture('pipeline-run')), array_keys($json));
        $this->assertIsArray($json['renditions']);
        $this->assertIsArray($json['renditions'][0]);
        $expected = self::fixture('pipeline-run')['renditions'];
        $this->assertIsArray($expected);
        $this->assertIsArray($expected[0]);
        $this->assertSame(array_keys($expected[0]), array_keys($json['renditions'][0]));
        $this->assertSame('completed', $json['status']);
        $this->assertNotNull($json['ended_at']);
    }
}
