<?php

namespace Folklore\Mediatheque\Tests\Feature\Http;

use Aws\S3\S3Client;
use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\MediaPolicy;
use Folklore\Mediatheque\Tests\Support\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The upload routes' environment, against an S3-compatible server (moto in CI, MinIO or
 * any S3 API locally). The tests that reach the storage are skipped unless
 * MEDIATHEQUE_TEST_S3_ENDPOINT is set, for example:
 *
 *     MEDIATHEQUE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit --filter UploadRoutesTest
 */
abstract class UploadRoutesTestCase extends DatabaseTestCase
{
    protected string $bucket;

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

    protected function requiresStorage(): void
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
    protected function user(array $attributes = []): User
    {
        $user = new User;
        $user->forceFill(['can_upload' => true, ...$attributes])->save();

        return $user;
    }

    /**
     * An open upload of this user, as `POST uploads` leaves it, without
     * reaching the storage.
     */
    protected function pendingUpload(User $user, ?string $providerUploadId = 'multipart-1'): Upload
    {
        $upload = new Upload;
        $uid = $upload->newUniqueId();
        $upload->forceFill([
            'uid' => $uid,
            'status' => UploadStatus::Pending,
            'disk' => 's3',
            'path' => 'mediatheque-uploads/'.$uid,
            'provider_upload_id' => $providerUploadId,
            'creator_type' => $user->getMorphClass(),
            'creator_id' => $user->getKey(),
            'expires_at' => Date::now()->addDay(),
        ])->save();

        return $upload;
    }

    protected function string(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }
}
