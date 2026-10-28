<?php

namespace Folklore\Mediatheque\Tests\Feature\Http;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Http\Resources\FileResource;
use Folklore\Mediatheque\Http\Resources\MediaResource;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The canonical shape of a media and its files (docs/decisions/0010), on
 * the fixtures of tests/fixtures/json/, which an adapter such as
 * laravel-panneau's tests its mapping against.
 */
class MediaResourceTest extends DatabaseTestCase
{
    use CreatesMedia;

    private const string UID = 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->travelTo('2026-10-21 14:00:00');
    }

    /**
     * A resource as a client receives it, through JSON.
     *
     * @return array<mixed>
     */
    private static function rendered(MediaResource|FileResource $resource): array
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

    private function assertMatchesFixture(string $fixture, MediaResource $resource): void
    {
        $json = self::rendered($resource);

        // JSON writes 5.0 as 5: numbers compare by value, keys in order
        $this->assertEquals(self::fixture($fixture), $json);
        $this->assertSame(array_keys(self::fixture($fixture)), array_keys($json));
    }

    /**
     * A video with its original, `h264`, poster and two thumbnails.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function video(array $attributes = []): Media
    {
        $media = $this->createMedia([
            'uid' => self::UID,
            'name' => 'Episode 1',
            'metadata' => [
                'kind' => 'video', 'mime' => 'video/quicktime', 'filename' => 'episode-1.mov', 'size' => 7340032,
                'width' => 1920, 'height' => 1080, 'duration' => 2851.2, 'frame_rate' => 25.0, 'video_codec' => 'h264', 'locale' => null,
            ],
            ...$attributes,
        ]);
        $this->createFile($media, 'original', ['name' => 'episode-1.mov', 'mime' => 'video/quicktime', 'size' => 7340032, 'width' => 1920, 'height' => 1080, 'duration' => 2851.2, 'path' => 'video/'.self::UID.'/original.mov']);
        $this->createFile($media, 'h264', ['name' => 'h264.mp4', 'mime' => 'video/mp4', 'size' => 2107637, 'width' => 1920, 'height' => 1080, 'duration' => 2851.2, 'bitrate' => 5913000, 'path' => 'video/'.self::UID.'/run/h264.mp4']);
        $this->createFile($media, 'poster', ['name' => 'poster.jpg', 'role' => 'poster', 'mime' => 'image/jpeg', 'size' => 81920, 'width' => 1920, 'height' => 1080, 'path' => 'video/'.self::UID.'/run/poster.jpg']);
        foreach ([0, 1] as $index) {
            $this->createFile($media, 'thumbnails', ['index' => $index, 'name' => 'thumbnails-'.$index.'.jpg', 'role' => 'thumbnail', 'mime' => 'image/jpeg', 'size' => 20480, 'width' => 640, 'height' => 360, 'path' => 'video/'.self::UID.'/run/thumbnails-'.$index.'.jpg']);
        }

        return $media->load('mediaFiles');
    }

    /**
     * A run of the video pipeline, with its renditions' statuses.
     *
     * @param  array<string, array<string, mixed>>  $jobs
     */
    private function createRun(Media $media, PipelineStatus $status, array $jobs, ?float $progress = null): void
    {
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
            ], 'main' => 'h264'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid.'/run',
            'visibility' => Visibility::Public,
            'status' => $status,
            'progress' => $progress,
            'started_at' => Date::now(),
        ])->save();

        foreach ($jobs as $name => $attributes) {
            (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => $name, 'queued_at' => Date::now(), ...$attributes])->save();
        }
    }

    #[Test]
    public function a_ready_video_renders_its_files_by_handle(): void
    {
        $this->assertMatchesFixture('media-video-ready', new MediaResource($this->video()));
    }

    #[Test]
    public function a_video_in_progress_renders_its_run(): void
    {
        $media = $this->video(['status' => MediaStatus::Processing]);
        $this->createRun($media, PipelineStatus::Running, [
            'h264' => ['status' => JobStatus::Running, 'progress' => 0.42, 'started_at' => Date::now()],
            'poster' => ['status' => JobStatus::Completed, 'progress' => 1.0, 'started_at' => Date::now(), 'ended_at' => Date::now()],
        ], progress: 0.42);

        $json = self::rendered(new MediaResource($media));

        $this->assertIsArray($json['processing']);
        $this->assertSame(['video', 'h264'], [$json['processing']['pipeline'], $json['processing']['rendition']]);
        $this->assertIsFloat($json['processing']['progress']);
        $this->assertEqualsWithDelta(0.42, $json['processing']['progress'], 0.01, 'Weighted by the input\'s duration.');
        $this->assertNull($json['error']);
        $this->assertMatchesFixture('media-video-processing', new MediaResource($media));
    }

    #[Test]
    public function a_failed_video_renders_why(): void
    {
        $media = $this->video(['status' => MediaStatus::Failed]);
        $this->createRun($media, PipelineStatus::Failed, [
            'h264' => ['status' => JobStatus::Failed, 'error' => ['code' => 'input_unreadable', 'message' => 'The video can\'t be decoded.'], 'ended_at' => Date::now()],
            'poster' => ['status' => JobStatus::Completed, 'ended_at' => Date::now()],
        ]);

        $json = self::rendered(new MediaResource($media));

        $this->assertSame(['code' => TranscoderErrorCode::InputUnreadable->value, 'message' => 'The video can\'t be decoded.'], $json['error']);
        $this->assertNull($json['processing']);
        $this->assertMatchesFixture('media-video-failed', new MediaResource($media));
    }

    #[Test]
    public function an_image_is_its_own_thumbnail(): void
    {
        $media = $this->createMedia([
            'uid' => self::UID,
            'type' => 'image',
            'name' => 'Portrait',
            'metadata' => ['kind' => 'image', 'mime' => 'image/jpeg', 'width' => 600, 'height' => 800, 'orientation' => 6],
            'transform' => ['crop' => ['x' => 0, 'y' => 100, 'width' => 600, 'height' => 600]],
        ]);
        $this->createFile($media, 'original', ['name' => 'portrait.jpg', 'mime' => 'image/jpeg', 'size' => 204800, 'width' => 600, 'height' => 800, 'path' => 'image/'.self::UID.'/original.jpg']);

        $this->assertMatchesFixture('media-image', new MediaResource($media->load('mediaFiles')));
    }

    #[Test]
    public function a_file_of_a_group_has_its_index_and_a_single_file_none(): void
    {
        $media = $this->video();

        $thumbnail = self::rendered(new FileResource($media->file('thumbnails', 1) ?? $this->fail()));
        $original = self::rendered(new FileResource($media->original() ?? $this->fail()));

        $this->assertSame(1, $thumbnail['index']);
        $this->assertArrayNotHasKey('index', $original);
        $this->assertArrayNotHasKey('bitrate', $original, 'A value it doesn\'t know is left out.');
        $this->assertArrayNotHasKey('id', $original, 'No internal id.');
    }

    #[Test]
    public function a_private_file_on_a_disk_without_urls_has_none(): void
    {
        config(['filesystems.disks.vault' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/mediatheque-vault']]);
        $media = $this->createMedia(['visibility' => Visibility::Private]);
        $this->createFile($media, 'original', ['source' => 'vault']);

        $json = self::rendered(new MediaResource($media->load('mediaFiles')));

        $this->assertNull($json['url']);
        $this->assertIsArray($json['files']);
        $this->assertIsArray($json['files']['original']);
        $this->assertNull($json['files']['original']['url']);
    }

    #[Test]
    public function a_media_of_the_fake_renders_the_same_shape(): void
    {
        $media = Mediatheque::fake()->ingest(UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'));

        $json = self::rendered(new MediaResource($media));

        $this->assertSame(array_keys(self::fixture('media-video-ready')), array_keys($json));
        $this->assertSame('ready', $json['status']);
        $this->assertIsArray($json['files']);
        $this->assertArrayHasKey('h264', $json['files']);
        $this->assertNull($json['created_at'], 'The fake\'s media has no timestamps.');
    }

    #[Test]
    public function an_empty_metadata_is_an_object(): void
    {
        $media = $this->createMedia(['metadata' => []]);

        $this->assertSame('{}', json_encode((new MediaResource($media))->toArray(new Request)['metadata']));
    }
}
