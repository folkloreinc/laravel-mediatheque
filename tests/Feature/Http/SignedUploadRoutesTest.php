<?php

namespace Folklore\Mediatheque\Tests\Feature\Http;

use Folklore\Mediatheque\Enums\UploadStatus;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\Upload;
use Folklore\Mediatheque\Tests\Support\User;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST uploads/sign`, for Uppy 6's `signRequest`: the client sends every S3
 * request itself, with a URL the package presigns, then completes the upload
 * without parts (docs/decisions/0016). Signing needs no storage; the tests
 * that send the requests are skipped unless MEDIATHEQUE_TEST_S3_ENDPOINT is
 * set.
 */
class SignedUploadRoutesTest extends UploadRoutesTestCase
{
    /**
     * @param  array<string, mixed>  $request  Uppy's `PresignableRequest`
     * @return TestResponse<Response>
     */
    private function sign(array $request): TestResponse
    {
        return $this->postJson('/mediatheque/uploads/sign', $request);
    }

    /**
     * Start an upload as Uppy does, with the key it proposes.
     *
     * @return array{url: string, key: string, upload: Upload}
     */
    private function start(User $user, string $method = 'POST'): array
    {
        $response = $this->actingAs($user)->sign(['method' => $method, 'key' => 'b3f1-clip.mp4'])->assertOk();
        $key = $this->string($response->json('key'));

        return [
            'url' => $this->string($response->json('url')),
            'key' => $key,
            'upload' => Upload::query()->where('uid', basename($key))->sole(),
        ];
    }

    /**
     * The query of a presigned URL.
     *
     * @return array<string, string>
     */
    private function presignedQuery(string $url): array
    {
        $query = [];
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($name)] = rawurldecode($value);
        }

        return $query;
    }

    /**
     * Send a presigned request to the storage, as the browser does.
     */
    private function sendToStorage(string $method, string $url, string $body = '', string $contentType = 'application/octet-stream'): ClientResponse
    {
        $response = Http::withBody($body, $contentType)->send($method, $url);
        $this->assertTrue($response->successful(), $response->body());

        return $response;
    }

    /**
     * Upload a file as Uppy 6 does, then complete it on the routes.
     *
     * @param  int<1, max>  $partSize
     * @return TestResponse<Response>
     */
    private function uploadAsUppy(User $user, string $contents, string $filename, bool $multipart, string $contentType, int $partSize = 5 * 1024 * 1024): TestResponse
    {
        if (! $multipart) {
            ['url' => $url, 'upload' => $upload] = $this->start($user, 'PUT');
            $this->sendToStorage('PUT', $url, $contents, $contentType);

            return $this->postJson('/mediatheque/uploads/'.$upload->uid.'/complete', ['filename' => $filename]);
        }

        ['url' => $url, 'key' => $key, 'upload' => $upload] = $this->start($user);
        $created = simplexml_load_string($this->sendToStorage('POST', $url, '', $contentType)->body());
        $uploadId = (string) ($created !== false ? $created->UploadId : '');

        $parts = '';
        foreach (str_split($contents, $partSize) as $index => $chunk) {
            $url = $this->string($this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => $uploadId, 'partNumber' => $index + 1])->assertOk()->json('url'));
            $etag = $this->sendToStorage('PUT', $url, $chunk)->header('ETag');
            $parts .= sprintf('<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', $index + 1, $etag);
        }

        $url = $this->string($this->sign(['method' => 'POST', 'key' => $key, 'uploadId' => $uploadId])->assertOk()->json('url'));
        $this->sendToStorage('POST', $url, '<CompleteMultipartUpload>'.$parts.'</CompleteMultipartUpload>', 'application/xml');

        return $this->postJson('/mediatheque/uploads/'.$upload->uid.'/complete', ['filename' => $filename]);
    }

    #[Test]
    public function urls_live_the_configured_lifetime_whatever_the_application_clock(): void
    {
        config(['mediatheque.uploads.url_ttl' => 600]);
        $this->travel(-30)->minutes();

        ['url' => $url] = $this->start($this->user());

        $this->assertSame('600', $this->presignedQuery($url)['X-Amz-Expires'] ?? null, 'The SDK signs with its own clock.');
    }

    #[Test]
    public function a_multipart_upload_gets_its_own_key(): void
    {
        config(['mediatheque.uploads.url_ttl' => 600]);
        $user = $this->user();

        ['url' => $url, 'key' => $key, 'upload' => $upload] = $this->start($user);

        $this->assertMatchesRegularExpression('#^mediatheque-uploads/upl_[0-9a-z]+$#i', $key, 'The key the client proposed is ignored.');
        $this->assertSame('/'.$this->bucket.'/'.$key, parse_url($url, PHP_URL_PATH));
        $query = $this->presignedQuery($url);
        $this->assertArrayHasKey('uploads', $query);
        $this->assertSame('600', $query['X-Amz-Expires']);
        $this->assertSame([UploadStatus::Pending, 's3', $key, null, null], [$upload->status, $upload->disk, $upload->path, $upload->provider_upload_id, $upload->filename]);
        $this->assertSame([$user->getMorphClass(), $user->getKey()], [$upload->creator_type, $upload->creator_id]);
    }

    #[Test]
    public function a_single_put_object_gets_its_own_key(): void
    {
        ['url' => $url, 'key' => $key] = $this->start($this->user(), 'PUT');

        $this->assertSame('/'.$this->bucket.'/'.$key, parse_url($url, PHP_URL_PATH));
        $this->assertArrayNotHasKey('uploads', $this->presignedQuery($url));
        $this->assertArrayNotHasKey('uploadId', $this->presignedQuery($url));
        $this->assertSame(1, Upload::query()->count());
    }

    #[Test]
    public function every_request_of_an_upload_is_signed_for_its_key(): void
    {
        $user = $this->user();
        ['key' => $key, 'upload' => $upload] = $this->start($user);

        $requests = [
            'UploadPart' => [['method' => 'PUT', 'uploadId' => 'multipart-1', 'partNumber' => 3], ['uploadId' => 'multipart-1', 'partNumber' => '3']],
            'ListParts' => [['method' => 'GET', 'uploadId' => 'multipart-1'], ['uploadId' => 'multipart-1']],
            'CompleteMultipartUpload' => [['method' => 'POST', 'uploadId' => 'multipart-1'], ['uploadId' => 'multipart-1']],
            'AbortMultipartUpload' => [['method' => 'DELETE', 'uploadId' => 'multipart-1'], ['uploadId' => 'multipart-1']],
            'DeleteObject' => [['method' => 'DELETE'], []],
        ];

        foreach ($requests as $operation => [$request, $expected]) {
            $response = $this->sign(['key' => $key, ...$request])->assertOk()->assertJsonPath('key', $key);
            $url = $this->string($response->json('url'));

            $this->assertSame('/'.$this->bucket.'/'.$key, parse_url($url, PHP_URL_PATH), $operation);
            $query = $this->presignedQuery($url);
            $this->assertSame($expected, array_intersect_key($query, ['uploadId' => true, 'partNumber' => true]), $operation);
            $this->assertArrayHasKey('X-Amz-Signature', $query, $operation);
        }

        $this->assertSame('multipart-1', $upload->refresh()->provider_upload_id, 'The first multipart upload signed for the key is recorded.');
        $this->assertSame(1, Upload::query()->count());
    }

    #[Test]
    public function another_users_key_is_refused(): void
    {
        ['key' => $key] = $this->start($this->user());
        $other = $this->user();

        foreach ([['method' => 'PUT', 'uploadId' => 'multipart-1', 'partNumber' => 1], ['method' => 'GET', 'uploadId' => 'multipart-1'], ['method' => 'POST', 'uploadId' => 'multipart-1'], ['method' => 'DELETE', 'uploadId' => 'multipart-1'], ['method' => 'DELETE']] as $request) {
            $this->actingAs($other)->sign(['key' => $key, ...$request])
                ->assertNotFound()
                ->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->assertNull(Upload::query()->sole()->provider_upload_id);
    }

    #[Test]
    public function a_key_that_is_not_an_uploads_key_is_refused(): void
    {
        $user = $this->user();
        ['key' => $key, 'upload' => $upload] = $this->start($user);

        foreach (['mediatheque-uploads/upl_01unknown', 'elsewhere/'.$upload->uid, $upload->uid, $key.'/', 'medias/'.$key] as $other) {
            $this->sign(['method' => 'PUT', 'key' => $other, 'uploadId' => 'multipart-1', 'partNumber' => 1])->assertNotFound();
        }
    }

    #[Test]
    public function only_the_first_multipart_upload_of_a_key_is_signed(): void
    {
        ['key' => $key] = $this->start($this->user());

        $this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => 'multipart-1', 'partNumber' => 1])->assertOk();
        $this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => 'multipart-2', 'partNumber' => 1])->assertNotFound();
        $this->sign(['method' => 'POST', 'key' => $key, 'uploadId' => 'multipart-2'])->assertNotFound();
        $this->sign(['method' => 'POST', 'key' => $key, 'uploadId' => 'multipart-1'])->assertOk();
    }

    #[Test]
    public function closed_uploads_are_refused(): void
    {
        $user = $this->user();
        $aborted = $this->start($user);
        $aborted['upload']->forceFill(['status' => UploadStatus::Aborted])->save();
        $completed = $this->start($user);
        $completed['upload']->forceFill(['status' => UploadStatus::Completed])->save();
        $expired = $this->start($user);

        $this->sign(['method' => 'DELETE', 'key' => $aborted['key']])->assertNotFound();
        $this->sign(['method' => 'DELETE', 'key' => $completed['key']])->assertNotFound();
        Date::setTestNow(Date::now()->addDays(2));
        $this->sign(['method' => 'DELETE', 'key' => $expired['key']])->assertStatus(410);
    }

    #[Test]
    public function requests_that_are_no_s3_request_are_refused(): void
    {
        $user = $this->user();
        ['key' => $key] = $this->start($user);

        $this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => 'multipart-1'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('reason', 'unknown_operation');
        $this->sign(['method' => 'GET', 'key' => $key])->assertUnprocessable()->assertJsonPath('reason', 'unknown_operation');
        $this->sign(['method' => 'POST', 'key' => $key, 'partNumber' => 1])->assertUnprocessable()->assertJsonPath('reason', 'unknown_operation');
        $this->sign(['method' => 'PATCH', 'key' => $key])->assertUnprocessable()->assertJsonStructure(['errors' => ['method']]);
        $this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => 'multipart-1', 'partNumber' => 10001])->assertUnprocessable()->assertJsonStructure(['errors' => ['partNumber']]);
        $this->sign(['method' => 'POST'])->assertUnprocessable()->assertJsonStructure(['errors' => ['key']]);
        $this->assertSame(1, Upload::query()->count());
    }

    #[Test]
    public function signing_needs_a_user_and_the_policy(): void
    {
        $this->sign(['method' => 'POST', 'key' => 'clip.mp4'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->actingAs($this->user(['can_upload' => false]))->sign(['method' => 'POST', 'key' => 'clip.mp4'])->assertForbidden();

        $this->assertSame(0, Upload::query()->count());
    }

    #[Test]
    public function an_upload_without_a_multipart_upload_cannot_be_completed_with_parts(): void
    {
        $user = $this->user();
        ['upload' => $upload] = $this->start($user, 'PUT');

        $this->postJson('/mediatheque/uploads/'.$upload->uid.'/complete', ['parts' => [['number' => 1, 'etag' => 'x']]])
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('reason', 'not_multipart');
    }

    #[Test]
    public function a_multipart_upload_completed_by_the_client_is_ingested(): void
    {
        $this->requiresStorage();
        $contents = (string) file_get_contents(self::fixturePath('media/test_vertical_hevc.mp4'));
        $this->assertCount(2, str_split($contents, 5 * 1024 * 1024));

        $response = $this->uploadAsUppy($this->user(), $contents, 'portrait.mp4', multipart: true, contentType: 'video/mp4')
            ->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('name', 'portrait.mp4')
            ->assertJsonPath('status', 'pending');

        $media = Media::query()->where('uid', $response->json('id'))->sole();
        $this->assertSame(strlen($contents), $media->original()?->size());
        $upload = Upload::query()->sole();
        $this->assertSame([UploadStatus::Completed, $media->id, 'portrait.mp4'], [$upload->status, $upload->media_id, $upload->filename]);
        Storage::disk('s3')->assertMissing($upload->path);
        Bus::assertDispatched(ProbeMedia::class);
    }

    #[Test]
    public function the_parts_received_are_listed_to_resume(): void
    {
        $this->requiresStorage();
        $contents = (string) file_get_contents(self::fixturePath('media/test_vertical_hevc.mp4'));
        $chunks = str_split($contents, 5 * 1024 * 1024);
        ['url' => $url, 'key' => $key, 'upload' => $upload] = $this->start($this->user());
        $created = simplexml_load_string($this->sendToStorage('POST', $url, '', 'video/mp4')->body());
        $uploadId = (string) ($created !== false ? $created->UploadId : '');

        $url = $this->string($this->sign(['method' => 'PUT', 'key' => $key, 'uploadId' => $uploadId, 'partNumber' => 1])->json('url'));
        $this->sendToStorage('PUT', $url, $chunks[0]);

        $url = $this->string($this->sign(['method' => 'GET', 'key' => $key, 'uploadId' => $uploadId])->assertOk()->json('url'));
        $listed = simplexml_load_string(Http::get($url)->body());
        $this->assertNotFalse($listed);
        $this->assertCount(1, $listed->Part);
        $this->assertSame('1', (string) $listed->Part[0]->PartNumber);

        $this->postJson('/mediatheque/uploads/'.$upload->uid.'/complete')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'incomplete');
        $this->assertSame(UploadStatus::Pending, $upload->refresh()->status);

        $url = $this->string($this->sign(['method' => 'DELETE', 'key' => $key, 'uploadId' => $uploadId])->assertOk()->json('url'));
        $this->assertSame(204, Http::delete($url)->status());
    }

    #[Test]
    public function a_single_put_object_is_ingested_with_the_detected_type(): void
    {
        $this->requiresStorage();

        // The client chooses the Content-Type of the object it sends; the media's file gets the detected one
        $response = $this->uploadAsUppy($this->user(), (string) file_get_contents(self::fixturePath('media/image.jpg')), 'photo.jpg', multipart: false, contentType: 'text/html')
            ->assertCreated()
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('name', 'photo.jpg');

        $original = Media::query()->where('uid', $response->json('id'))->sole()->original();
        $this->assertSame('image/jpeg', Storage::disk('s3')->mimeType((string) $original?->path()));
        Storage::disk('s3')->assertMissing(Upload::query()->sole()->path);
    }

    #[Test]
    public function completing_twice_answers_with_the_same_media(): void
    {
        $this->requiresStorage();
        $user = $this->user();
        $first = $this->uploadAsUppy($user, (string) file_get_contents(self::fixturePath('media/image.jpg')), 'photo.jpg', multipart: false, contentType: 'image/jpeg')->assertCreated();

        $this->actingAs($user)->postJson('/mediatheque/uploads/'.Upload::query()->sole()->uid.'/complete')
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, Media::query()->count());
    }

    #[Test]
    public function a_rejected_file_is_deleted(): void
    {
        $this->requiresStorage();

        $this->uploadAsUppy($this->user(), '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'photo.jpg', multipart: false, contentType: 'image/jpeg')
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'unknown_type');

        $this->assertSame(0, Media::query()->count());
        $this->assertSame(UploadStatus::Aborted, Upload::query()->sole()->status);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }
}
