<?php

namespace Folklore\Mediatheque\Tests\Feature\Uploads;

use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Exceptions\UnknownS3Operation;
use Folklore\Mediatheque\Exceptions\UploadExpired;
use Folklore\Mediatheque\Exceptions\UploadIncomplete;
use Folklore\Mediatheque\Exceptions\UploadNotFound;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Tests\Feature\Http\UploadRoutesTestCase;
use Folklore\Mediatheque\Tests\Support\User;
use Folklore\Mediatheque\Uploads\DirectUploads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * The services behind the upload routes, called without them, as a package
 * that offers the same flow under its own API does (docs/decisions/0018).
 * Signing needs no storage. Completion and ingest run on a local disk: a
 * client that sent its file with a presigned `PutObject` leaves it there.
 */
class DirectUploadsTest extends UploadRoutesTestCase
{
    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // No Route::mediatheque(): the services don't need the package's routes
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.disks.uploads', ['driver' => 'local', 'root' => sys_get_temp_dir().'/mediatheque-uploads']);
        $app['config']->set('filesystems.disks.media', ['driver' => 'local', 'root' => sys_get_temp_dir().'/mediatheque-media']);
        $app['config']->set('mediatheque.source', 'media');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
        Storage::fake('media');
    }

    private function uploads(): DirectUploads
    {
        return app(DirectUploads::class);
    }

    /**
     * An open upload of this user on the local `uploads` disk, with the file
     * the client sent, as a presigned `PutObject` leaves it.
     */
    private function sentUpload(User $user, ?string $contents = null): Upload
    {
        $upload = $this->pendingUpload($user, providerUploadId: null);
        $upload->forceFill(['disk' => 'uploads'])->save();

        if ($contents !== null) {
            Storage::disk('uploads')->put($upload->path, $contents);
        }

        return $upload;
    }

    private function image(): string
    {
        return (string) file_get_contents(self::fixturePath('media/image.jpg'));
    }

    /**
     * @param  class-string<Throwable>  $expected
     */
    private function assertFails(string $expected, callable $action, string $message = ''): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expected, $exception, $message);

            return;
        }

        $this->fail($message !== '' ? $message : sprintf('%s was not thrown.', $expected));
    }

    #[Test]
    public function the_package_routes_are_not_registered(): void
    {
        $this->assertFalse(app('router')->has('mediatheque.uploads.sign'));
        $this->assertFalse(app('router')->has('mediatheque.uploads.complete'));
    }

    #[Test]
    public function signing_a_new_upload_names_it(): void
    {
        $user = $this->user();

        $signed = $this->uploads()->sign($user, 'POST', 'b3f1-clip.mp4');

        $upload = Upload::query()->sole();
        $this->assertTrue($signed->created);
        $this->assertSame($upload->uid, $signed->upload->uid);
        $this->assertSame('mediatheque-uploads/'.$upload->uid, $signed->key);
        $this->assertSame(['url' => $signed->url, 'key' => $signed->key, 'upload' => $upload->uid], $signed->toArray());
        $this->assertSame(json_encode($signed->toArray()), json_encode($signed));
        $this->assertSame([$user->getMorphClass(), $user->getKey(), UploadStatus::Pending], [$upload->creator_type, $upload->creator_id, $upload->status]);

        $part = $this->uploads()->sign($user, 'PUT', $signed->key, 'multipart-1', 1);
        $this->assertFalse($part->created);
        $this->assertSame($upload->uid, $part->upload->uid);
        $this->assertSame(['url', 'key'], array_keys($part->toArray()), 'Only the request that starts an upload names it.');
        $this->assertSame('multipart-1', $upload->refresh()->provider_upload_id);

        $this->assertTrue($this->uploads()->sign($user, 'PUT', 'photo.jpg')->created, 'A PutObject starts an upload too.');
        $this->assertFails(UnknownS3Operation::class, fn () => $this->uploads()->sign($user, 'GET', $signed->key));
    }

    #[Test]
    public function a_key_is_found_only_for_its_owner(): void
    {
        $user = $this->user();
        $key = $this->uploads()->sign($user, 'POST', 'clip.mp4')->key;
        $upload = Upload::query()->sole();

        $this->assertSame($upload->uid, $this->uploads()->findByKey($user, $key)->uid);
        $this->assertSame($key, $this->uploads()->key($upload));
        $this->assertSame($upload->uid, $this->uploads()->findByKey($user, $key, 'multipart-1')->uid);
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->findByKey($user, $key, 'multipart-2'), 'Only the first multipart upload of a key is accepted.');

        $other = $this->user();
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->findByKey($other, $key));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->findByKey($user, 'elsewhere/'.$upload->uid));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->findByKey($user, $upload->uid));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->sign($other, 'PUT', $key, 'multipart-1', 1));
    }

    #[Test]
    public function an_upload_sent_by_the_client_is_completed_then_ingested(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user, $this->image());

        $completed = $this->uploads()->complete($user, $upload->uid, filename: 'photo.jpg');

        $this->assertSame([UploadStatus::Pending, 'photo.jpg', strlen($this->image())], [$completed->status, $completed->filename, $completed->size], 'Completion records the name and the stored size, and leaves the upload open.');
        $this->assertSame(0, Media::query()->count(), 'Completion doesn\'t ingest.');
        Storage::disk('uploads')->assertExists($upload->path);

        $media = $this->uploads()->ingest($user, $completed);

        $this->assertSame(['image', 'photo.jpg'], [$media->type(), $media->name()]);
        $upload->refresh();
        $this->assertSame([UploadStatus::Completed, Media::query()->sole()->id], [$upload->status, $upload->media_id]);
        Storage::disk('uploads')->assertMissing($upload->path);
        Bus::assertDispatched(ProbeMedia::class);

        $this->assertSame($media->uid(), $this->uploads()->ingest($user, $upload->uid)->uid(), 'A completed upload gives the media it created.');
        $this->assertSame(UploadStatus::Completed, $this->uploads()->complete($user, $upload)->status);
        $this->assertSame(1, Media::query()->count());
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->find($user, $upload), 'A completed upload is no longer open.');
        $this->assertSame($upload->uid, $this->uploads()->find($user, $upload, completed: true)->uid);
    }

    #[Test]
    public function the_media_gets_the_options_of_uploads_using(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user, $this->image());
        $received = null;
        Mediatheque::uploadsUsing(function (Request $request, Upload $upload) use (&$received) {
            $received = $upload->uid;

            return new IngestOptions(visibility: Visibility::Private, attributes: ['space_id' => 7]);
        });

        $media = $this->uploads()->ingest($user, $upload->uid);

        $this->assertSame($upload->uid, $received);
        $this->assertSame([Visibility::Private, 7], [$media->visibility(), Media::query()->sole()->getAttribute('space_id')]);

        $other = $this->sentUpload($user, $this->image());
        $media = $this->uploads()->ingest($user, $other, new IngestOptions(attributes: ['space_id' => 9]));
        $this->assertSame([Visibility::Public, 9], [$media->visibility(), Media::query()->where('uid', $media->uid())->sole()->getAttribute('space_id')], 'Options given replace those of uploadsUsing().');
    }

    #[Test]
    public function another_users_upload_does_not_exist_for_them(): void
    {
        $upload = $this->sentUpload($this->user(), $this->image());
        $other = $this->user();

        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->find($other, $upload->uid));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->find($other, $upload), 'An upload given as a model is checked too.');
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->complete($other, $upload));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->ingest($other, $upload));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->signParts($other, $upload, [1]));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->parts($other, $upload));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->abort($other, $upload));
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->find($other, 'upl_01unknown'));

        $this->assertSame(UploadStatus::Pending, $upload->refresh()->status);
        $this->assertSame(0, Media::query()->count());
        Storage::disk('uploads')->assertExists($upload->path);
    }

    #[Test]
    public function the_policy_authorizes_every_service(): void
    {
        // Even the user who created the upload, once the policy denies them
        $denied = $this->user(['can_upload' => false]);
        $upload = $this->sentUpload($denied, $this->image());

        foreach ([
            'create' => fn () => $this->uploads()->create($denied, 'clip.mp4', 10),
            'sign' => fn () => $this->uploads()->sign($denied, 'POST', 'clip.mp4'),
            'find' => fn () => $this->uploads()->find($denied, $upload),
            'findByKey' => fn () => $this->uploads()->findByKey($denied, $upload->path),
            'signParts' => fn () => $this->uploads()->signParts($denied, $upload, [1]),
            'parts' => fn () => $this->uploads()->parts($denied, $upload),
            'complete' => fn () => $this->uploads()->complete($denied, $upload),
            'ingest' => fn () => $this->uploads()->ingest($denied, $upload),
            'abort' => fn () => $this->uploads()->abort($denied, $upload),
        ] as $service => $action) {
            $this->assertFails(AuthorizationException::class, $action, $service);
        }

        $this->assertSame(1, Upload::query()->count());
        $this->assertSame(0, Media::query()->count());
    }

    #[Test]
    public function a_rejected_file_is_deleted_and_aborts_the_upload(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
        $this->uploads()->complete($user, $upload, filename: 'photo.jpg');

        try {
            $this->uploads()->ingest($user, $upload);
            $this->fail('The file was ingested.');
        } catch (FileRejected $exception) {
            $this->assertSame('unknown_type', $exception->reason->value);
        }

        $upload->refresh();
        $this->assertSame([UploadStatus::Aborted, 'unknown_type'], [$upload->status, $upload->reason]);
        $this->assertSame(0, Media::query()->count());
        Storage::disk('uploads')->assertMissing($upload->path);
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->ingest($user, $upload), 'A rejected upload is gone.');
    }

    #[Test]
    public function a_file_that_is_not_on_the_storage_yet_is_not_ingested(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user);

        $this->assertFails(UploadIncomplete::class, fn () => $this->uploads()->complete($user, $upload));
        $this->assertFails(UploadIncomplete::class, fn () => $this->uploads()->ingest($user, $upload), 'Ingest doesn\'t reject a file the client is still sending.');
        try {
            $this->uploads()->complete($user, $upload, [['number' => 1, 'etag' => 'x']]);
            $this->fail('An upload without a multipart upload was completed with parts.');
        } catch (UploadIncomplete $exception) {
            $this->assertSame(UploadIncomplete::NOT_MULTIPART, $exception->reason);
        }
        $this->assertFails(UploadIncomplete::class, fn () => $this->uploads()->signParts($user, $upload, [1]));

        $this->assertSame([UploadStatus::Pending, null], [$upload->refresh()->status, $upload->reason]);

        Storage::disk('uploads')->put($upload->path, $this->image());
        $this->assertSame('image', $this->uploads()->ingest($user, $upload)->type(), 'The client can complete it later.');
    }

    #[Test]
    public function an_expired_upload_cannot_be_continued(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user, $this->image());
        Date::setTestNow(Date::now()->addDays(2));

        $this->assertFails(UploadExpired::class, fn () => $this->uploads()->find($user, $upload));
        $this->assertFails(UploadExpired::class, fn () => $this->uploads()->complete($user, $upload));
        $this->assertFails(UploadExpired::class, fn () => $this->uploads()->ingest($user, $upload));
        $this->assertSame(0, Media::query()->count());
    }

    #[Test]
    public function an_upload_without_a_multipart_upload_is_aborted_with_its_file(): void
    {
        $user = $this->user();
        $upload = $this->sentUpload($user, $this->image());

        $this->uploads()->abort($user, $upload);

        $upload->refresh();
        $this->assertSame([UploadStatus::Aborted, Upload::ABORTED], [$upload->status, $upload->reason]);
        Storage::disk('uploads')->assertMissing($upload->path);
        $this->assertFails(UploadNotFound::class, fn () => $this->uploads()->abort($user, $upload));
    }

    #[Test]
    public function a_file_larger_than_every_type_allows_is_refused_before_it_is_sent(): void
    {
        config(['mediatheque.types' => ['video' => ['mimes' => ['video/*'], 'max_size' => 1000]], 'mediatheque.ingest.max_size' => 1000]);

        try {
            $this->uploads()->create($this->user(), 'clip.mp4', 2000);
            $this->fail('The upload was created.');
        } catch (FileRejected $exception) {
            $this->assertSame('too_large', $exception->reason->value);
        }

        $this->assertSame(0, Upload::query()->count());
    }
}
