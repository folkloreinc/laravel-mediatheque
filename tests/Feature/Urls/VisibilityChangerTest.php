<?php

namespace Folklore\Mediatheque\Tests\Feature\Urls;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\ApplyVisibility;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The Eloquent side of Mediatheque::setVisibility(): the queued job, and
 * object ACLs on an S3-compatible disk. The contract tests run in
 * MediathequeManagerTest.
 */
class VisibilityChangerTest extends DatabaseTestCase
{
    use CreatesMedia;

    /** @var list<array{string, string, string|null}> The S3 commands sent: name, key, ACL */
    private array $commands = [];

    /** @var list<string> The S3 commands that fail */
    private array $failing = [];

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A local disk that serves its private files through signed URLs
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediatheque-visibility',
            'url' => 'https://cdn.example.com/media',
            'serve' => true,
        ]);

        // An S3-compatible disk whose requests are answered here
        $app['config']->set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'key',
            'secret' => 'secret',
            'region' => 'nyc3',
            'bucket' => 'media',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'handler' => function (CommandInterface $command, RequestInterface $request) {
                $key = $command['Key'];
                $acl = $command['ACL'];
                $this->commands[] = [$command->getName(), is_string($key) ? $key : '', is_string($acl) ? $acl : null];

                return in_array($command->getName(), $this->failing, true)
                    ? Create::rejectionFor(new S3Exception('Access denied', $command))
                    : Create::promiseFor(new Result([]));
            },
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('media')->deleteDirectory('video');
    }

    /**
     * A public video with its original and `h264` rendition on a disk.
     */
    private function video(string $disk = 'media'): Media
    {
        $media = $this->createMedia(['metadata' => ['kind' => 'video']]);
        foreach (['original', 'h264'] as $handle) {
            $file = $this->createFile($media, $handle, ['source' => $disk, 'path' => 'video/'.$media->uid.'/'.$handle.'.mp4', 'mime' => 'video/mp4']);
            if ($disk === 'media') {
                Storage::disk('media')->put((string) $file->path, $handle, 'public');
            }
        }

        return $media->load('mediaFiles');
    }

    /**
     * @return list<string|null>
     */
    private function visibilities(Media $media): array
    {
        $fresh = Media::withTrashed()->with('mediaFiles')->findOrFail($media->id);

        return array_values($fresh->files()->map(fn (File $file) => Storage::disk('media')->getVisibility((string) $file->path()))->all());
    }

    #[Test]
    public function urls_follow_at_once_and_the_files_in_a_queued_job(): void
    {
        Bus::fake();
        $media = $this->video();
        $this->assertSame('https://cdn.example.com/media/video/'.$media->uid.'/original.mp4', $media->url('original'));

        $this->assertTrue($media->setVisibility(Visibility::Private));

        $this->assertStringContainsString('signature=', (string) $media->url('original'), 'A private media gets temporary URLs before its files follow.');
        $this->assertSame(['public', 'public'], $this->visibilities($media));
        Bus::assertDispatched(ApplyVisibility::class, fn (ApplyVisibility $job) => $job->media === $media->uid && $job->afterCommit === true);
        Bus::assertDispatchedTimes(ApplyVisibility::class, 1);
    }

    #[Test]
    public function the_job_applies_the_visibility_the_media_has_when_it_runs(): void
    {
        Bus::fake();
        $media = $this->video();
        $media->setVisibility(Visibility::Private);
        $media->setVisibility(Visibility::Public);

        foreach (Bus::dispatched(ApplyVisibility::class) as $job) {
            $this->assertInstanceOf(ApplyVisibility::class, $job);
            $job->handle(app(Filesystems::class));
        }

        $this->assertSame(['public', 'public'], $this->visibilities($media), 'The later change wins.');
    }

    #[Test]
    public function running_the_job_again_changes_nothing(): void
    {
        $media = $this->video();
        $media->setVisibility(Visibility::Private);

        (new ApplyVisibility($media->uid))->handle(app(Filesystems::class));

        $this->assertSame(['private', 'private'], $this->visibilities($media));
    }

    #[Test]
    public function a_file_its_disk_does_not_have_is_skipped(): void
    {
        $media = $this->video();
        Storage::disk('media')->delete('video/'.$media->uid.'/h264.mp4');

        $this->assertTrue($media->setVisibility(Visibility::Private));

        $this->assertSame('private', Storage::disk('media')->getVisibility('video/'.$media->uid.'/original.mp4'));
    }

    #[Test]
    public function a_deleted_media_gets_private_files_too(): void
    {
        $media = $this->video();
        $media->delete();

        $this->assertTrue(Mediatheque::setVisibility($media, Visibility::Private));

        $this->assertSame(Visibility::Private, Media::withTrashed()->findOrFail($media->id)->visibility());
        $this->assertSame(['private', 'private'], $this->visibilities($media));
    }

    #[Test]
    public function an_s3_disk_gets_object_acls(): void
    {
        $media = $this->video('spaces');

        $media->setVisibility(Visibility::Private);

        $this->assertEqualsCanonicalizing([
            ['PutObjectAcl', 'video/'.$media->uid.'/original.mp4', 'private'],
            ['PutObjectAcl', 'video/'.$media->uid.'/h264.mp4', 'private'],
        ], $this->commands);
    }

    #[Test]
    public function a_file_whose_visibility_cannot_be_set_fails_the_job(): void
    {
        Bus::fake();
        $media = $this->video('spaces');
        $media->setVisibility(Visibility::Private);
        $this->failing = ['PutObjectAcl'];

        try {
            (new ApplyVisibility($media->uid))->handle(app(Filesystems::class));
            $this->fail('The job did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('can\'t be made private', $exception->getMessage());
        }
    }

    #[Test]
    public function the_job_of_a_media_that_is_gone_does_nothing(): void
    {
        $job = new ApplyVisibility('med_gone');

        $job->handle(app(Filesystems::class));

        $this->assertSame([], $this->commands);
    }
}
