<?php

namespace Folklore\Mediatheque\Tests\Feature\Http;

use Aws\S3\S3Client;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Fixtures\MediaPolicy;
use Folklore\Mediatheque\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The upload routes, against an S3-compatible server (moto in CI, MinIO or
 * any S3 API locally). The tests that reach the storage are skipped unless
 * MEDIATHEQUE_TEST_S3_ENDPOINT is set, for example:
 *
 *     MEDIATHEQUE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit --filter UploadRoutesTest
 */
class UploadRoutesTest extends DatabaseTestCase
{
    private string $bucket;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->bucket = 'mediatheque-'.bin2hex(random_bytes(4));
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => getenv('MEDIATHEQUE_TEST_S3_KEY') ?: 'testing',
            'secret' => getenv('MEDIATHEQUE_TEST_S3_SECRET') ?: 'testing',
            'region' => 'us-east-1',
            'bucket' => $this->bucket,
            'endpoint' => getenv('MEDIATHEQUE_TEST_S3_ENDPOINT') ?: 'http://127.0.0.1:1',
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);
        $app['config']->set('mediatheque.source', 's3');
    }

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        // As an application does it, in its routes file: Route::mediatheque()
        $router->middleware([])->group(fn () => $router->__call('mediatheque', []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->boolean('can_upload')->default(true);
            $table->unsignedBigInteger('space_id')->nullable();
        });
        Schema::table('mediatheque_medias', fn (Blueprint $table) => $table->unsignedBigInteger('space_id')->nullable());
        Gate::policy(Media::class, MediaPolicy::class);
        Bus::fake([ProbeMedia::class]);
    }

    private function requiresStorage(): void
    {
        if (! getenv('MEDIATHEQUE_TEST_S3_ENDPOINT')) {
            $this->markTestSkipped('Set MEDIATHEQUE_TEST_S3_ENDPOINT to run the tests against an S3-compatible server.');
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => getenv('MEDIATHEQUE_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => getenv('MEDIATHEQUE_TEST_S3_KEY') ?: 'testing', 'secret' => getenv('MEDIATHEQUE_TEST_S3_SECRET') ?: 'testing'],
        ]);
        $client->createBucket(['Bucket' => $this->bucket]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(array $attributes = []): User
    {
        $user = new User;
        $user->forceFill(['can_upload' => true, ...$attributes])->save();

        return $user;
    }

    private function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /**
     * Upload a file through the routes, as Uppy does: create, sign each part,
     * send it, complete.
     *
     * @param  int<1, max>  $partSize
     * @return TestResponse<Response>
     */
    private function uploadThroughRoutes(User $user, string $contents, string $filename, int $partSize = 5 * 1024 * 1024): TestResponse
    {
        $id = $this->actingAs($user)->postJson('/mediatheque/uploads', ['filename' => $filename, 'size' => strlen($contents)])
            ->assertCreated()
            ->json('id');
        $this->assertIsString($id);

        $chunks = str_split($contents, $partSize);
        $signed = $this->postJson('/mediatheque/uploads/'.$id.'/parts', ['part_numbers' => range(1, count($chunks))])
            ->assertOk()
            ->json('parts');
        $this->assertIsArray($signed);

        $parts = [];
        foreach ($signed as $position => $part) {
            $this->assertIsArray($part);
            $response = Http::withBody($chunks[$position], 'application/octet-stream')->put($this->string($part['url']));
            $this->assertTrue($response->successful(), $response->body());
            $parts[] = ['number' => $part['number'], 'etag' => $response->header('ETag')];
        }

        return $this->postJson('/mediatheque/uploads/'.$id.'/complete', ['parts' => $parts]);
    }

    #[Test]
    public function the_routes_are_registered_by_the_application_only(): void
    {
        $this->assertTrue(app('router')->has('mediatheque.uploads.store'));
        $this->assertTrue(app('router')->has('mediatheque.uploads.complete'));
    }

    #[Test]
    public function guests_cannot_upload(): void
    {
        $this->postJson('/mediatheque/uploads', ['filename' => 'clip.mp4', 'size' => 10])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function the_policy_decides_who_can_upload(): void
    {
        $this->actingAs($this->user(['can_upload' => false]))
            ->postJson('/mediatheque/uploads', ['filename' => 'clip.mp4', 'size' => 10])
            ->assertForbidden()
            ->assertJsonPath('status', 403);
    }

    #[Test]
    public function without_a_policy_everything_is_denied(): void
    {
        Gate::policy(Media::class, \stdClass::class);
        app()->forgetInstance(\Illuminate\Contracts\Auth\Access\Gate::class);
        Gate::clearResolvedInstances();

        $this->actingAs($this->user())
            ->postJson('/mediatheque/uploads', ['filename' => 'clip.mp4', 'size' => 10])
            ->assertForbidden();
    }

    #[Test]
    public function requests_are_validated(): void
    {
        $this->actingAs($this->user())
            ->postJson('/mediatheque/uploads', ['filename' => '', 'size' => 'big'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['errors' => ['filename', 'size']]);
    }

    #[Test]
    public function a_file_larger_than_every_type_allows_is_refused_before_it_is_sent(): void
    {
        config(['mediatheque.types' => ['video' => ['mimes' => ['video/*'], 'max_size' => 1000], 'image' => ['mimes' => ['image/*'], 'pipeline' => null]], 'mediatheque.ingest.max_size' => 1000]);

        $this->actingAs($this->user())
            ->postJson('/mediatheque/uploads', ['filename' => 'clip.mp4', 'size' => 2000])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'too_large');
        $this->assertSame(0, Upload::query()->count());
    }

    #[Test]
    public function an_uploaded_video_is_ingested_into_the_application_context(): void
    {
        $this->requiresStorage();
        $user = $this->user(['space_id' => 42]);
        Mediatheque::uploadsUsing(fn (Request $request, Upload $upload) => new IngestOptions(
            visibility: Visibility::Private,
            attributes: ['space_id' => $request->user()?->getAttribute('space_id')],
        ));

        $response = $this->uploadThroughRoutes($user, (string) file_get_contents(self::fixturePath('media/test.mp4')), 'clip.mp4')
            ->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('visibility', 'private');

        $media = Media::query()->where('uid', $response->json('id'))->sole();
        $original = $media->original();
        $this->assertSame([42, 'clip.mp4', 's3'], [$media->getAttribute('space_id'), $media->name(), $original?->source()]);
        $this->assertSame(hash_file('sha256', self::fixturePath('media/test.mp4')), hash('sha256', (string) Storage::disk('s3')->get((string) $original?->path())));
        $this->assertSame('private', Storage::disk('s3')->getVisibility((string) $original?->path()));
        $upload = Upload::query()->sole();
        $this->assertSame([UploadStatus::Completed, $media->id], [$upload->status, $upload->media_id]);
        Storage::disk('s3')->assertMissing($upload->path);
        Bus::assertDispatched(ProbeMedia::class);

        $this->assertStringContainsString('X-Amz-Signature=', $this->string($media->url()), 'A private file on S3 gets a signed URL.');
    }

    #[Test]
    public function a_file_in_several_parts_resumes_from_the_parts_received(): void
    {
        $this->requiresStorage();
        $user = $this->user();
        $contents = (string) file_get_contents(self::fixturePath('media/test_vertical_hevc.mp4'));
        $chunks = str_split($contents, 5 * 1024 * 1024);
        $this->assertCount(2, $chunks);

        $id = $this->string($this->actingAs($user)->postJson('/mediatheque/uploads', ['filename' => 'portrait.mp4', 'size' => strlen($contents)])->json('id'));
        $url = $this->string($this->postJson('/mediatheque/uploads/'.$id.'/parts', ['part_numbers' => [1]])->json('parts.0.url'));
        Http::withBody($chunks[0], 'application/octet-stream')->put($url);

        $received = $this->getJson('/mediatheque/uploads/'.$id.'/parts')->assertOk()->json('parts');
        $this->assertIsArray($received);
        $this->assertCount(1, $received);

        $url = $this->string($this->postJson('/mediatheque/uploads/'.$id.'/parts', ['part_numbers' => [2]])->json('parts.0.url'));
        $etag = Http::withBody($chunks[1], 'application/octet-stream')->put($url)->header('ETag');

        $this->postJson('/mediatheque/uploads/'.$id.'/complete', ['parts' => [
            ['number' => 1, 'etag' => data_get($received, '0.etag')],
            ['number' => 2, 'etag' => $etag],
        ]])->assertCreated()->assertJsonPath('type', 'video');

        $media = Media::query()->sole();
        $this->assertSame(strlen($contents), $media->original()?->size());
        $this->assertSame(MediaStatus::Pending, $media->status());
    }

    #[Test]
    public function a_rejected_file_is_deleted_and_creates_nothing(): void
    {
        $this->requiresStorage();

        $this->uploadThroughRoutes($this->user(), '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'photo.jpg')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('reason', 'unknown_type');

        $this->assertSame(0, Media::query()->count());
        $this->assertSame(UploadStatus::Aborted, Upload::query()->sole()->status);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    #[Test]
    public function another_users_upload_does_not_exist_for_them(): void
    {
        $this->requiresStorage();
        $id = $this->string($this->actingAs($this->user())->postJson('/mediatheque/uploads', ['filename' => 'clip.mp4', 'size' => 10])->json('id'));

        $other = $this->user();
        $this->actingAs($other)->postJson('/mediatheque/uploads/'.$id.'/parts', ['part_numbers' => [1]])->assertNotFound();
        $this->actingAs($other)->postJson('/mediatheque/uploads/'.$id.'/complete', ['parts' => [['number' => 1, 'etag' => 'x']]])->assertNotFound();
        $this->actingAs($other)->deleteJson('/mediatheque/uploads/'.$id)->assertNotFound();
        $this->assertSame(UploadStatus::Pending, Upload::query()->sole()->status);
    }

    #[Test]
    public function an_aborted_upload_is_gone_and_an_expired_one_is_refused(): void
    {
        $this->requiresStorage();
        $user = $this->user();
        $aborted = $this->string($this->actingAs($user)->postJson('/mediatheque/uploads', ['filename' => 'a.mp4', 'size' => 10])->json('id'));
        $expired = $this->string($this->postJson('/mediatheque/uploads', ['filename' => 'b.mp4', 'size' => 10])->json('id'));

        $this->deleteJson('/mediatheque/uploads/'.$aborted)->assertNoContent();
        $this->postJson('/mediatheque/uploads/'.$aborted.'/parts', ['part_numbers' => [1]])->assertNotFound();

        Date::setTestNow(Date::now()->addDays(2));
        $this->postJson('/mediatheque/uploads/'.$expired.'/parts', ['part_numbers' => [1]])->assertStatus(410);
    }

    #[Test]
    public function completing_twice_answers_with_the_same_media(): void
    {
        $this->requiresStorage();
        $user = $this->user();
        $first = $this->uploadThroughRoutes($user, (string) file_get_contents(self::fixturePath('media/image.jpg')), 'photo.jpg')->assertCreated();
        $id = Upload::query()->sole()->uid;

        $this->actingAs($user)->postJson('/mediatheque/uploads/'.$id.'/complete', ['parts' => [['number' => 1, 'etag' => 'x']]])
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, Media::query()->count());
    }

    #[Test]
    public function client_fields_never_set_the_media_attributes(): void
    {
        $this->requiresStorage();

        $response = $this->uploadThroughRoutes($this->user(['space_id' => 1]), (string) file_get_contents(self::fixturePath('media/image.jpg')), 'photo.jpg');

        $media = Media::query()->where('uid', $response->json('id'))->sole();
        $this->assertNull($media->getAttribute('space_id'), 'Without uploadsUsing(), no attribute is set.');
        $this->assertSame(Visibility::Public, $media->visibility());
    }
}
