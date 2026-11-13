<?php

namespace Folklore\Mediatheque\Tests\Feature\Embeds;

use Closure;
use Folklore\Mediatheque\Embeds\EmbedProviders;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Http\Resources\MediaResource;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use PHPUnit\Framework\Attributes\Test;

/**
 * An embed's poster (docs/decisions/0031): the `embed` pipeline downloads
 * the provider's thumbnail from its domains (`embed.thumbnail`), and stores
 * a JPEG of it. The queue is sync: the probe, then the pipeline, run at
 * ingest.
 */
class EmbedPosterTest extends DatabaseTestCase
{
    private const string THUMBNAIL = 'https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg';

    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            LocalFiles::removeDirectory($directory);
        }

        parent::tearDown();
    }

    /**
     * @return array<mixed>
     */
    private static function answer(string $name): array
    {
        $json = json_decode((string) file_get_contents(self::fixturePath('embeds/'.$name.'.json')), true);

        return is_array($json) ? $json : [];
    }

    private function jpeg(): string
    {
        return (string) file_get_contents(self::fixturePath('media/image.jpg'));
    }

    /**
     * Run `embed.thumbnail` on a media's metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function thumbnail(array $metadata): LocalOutput
    {
        $directory = app(LocalFiles::class)->temporaryDirectory();
        $this->directories[] = $directory;

        $outputs = app(FfmpegTranscoder::class)->operation('embed.thumbnail')->run(new OperationContext(
            input: '',
            outputDirectory: $directory,
            rendition: Rendition::fromArray('thumbnail', ['op' => 'embed.thumbnail', 'keep' => false]),
            metadata: $metadata,
            progress: Closure::fromCallable(fn (float $progress) => null),
            timeout: 60,
        ));
        $this->assertCount(1, $outputs);

        return $outputs[0];
    }

    private function failure(Closure $run): TranscoderErrorCode
    {
        try {
            $run();
        } catch (OperationFailed $exception) {
            return $exception->error->code;
        }
        $this->fail('The operation did not fail.');
    }

    #[Test]
    public function a_probed_embed_gets_a_poster_from_its_providers_thumbnail(): void
    {
        $this->requiresImagick();
        Http::fake([
            'www.youtube.com/oembed*' => Http::response(self::answer('youtube-oembed')),
            self::THUMBNAIL => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $ingested = Mediatheque::ingest(Source::embed('https://youtu.be/aqz-KE-bpKQ'));
        $media = Media::query()->with('mediaFiles')->where('uid', $ingested->uid())->firstOrFail();

        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertTrue($media->isEmbed(), 'The embed stays an embed: only its poster is stored.');
        $poster = $media->file('poster');
        $this->assertNotNull($poster);
        $this->assertSame(['image/jpeg', 'poster'], [$poster->mime(), $poster->role()]);
        $this->assertGreaterThan(0, $poster->width());
        $this->assertIsString($poster->metadata('thumbhash'));
        $this->assertTrue(Storage::disk('public')->exists((string) $poster->path()));
        $this->assertNull($media->file('thumbnail'), 'The downloaded thumbnail is not kept.');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::THUMBNAIL);

        $json = (new MediaResource($media))->toArray(new Request);
        $this->assertSame($poster->url(), $json['thumbnail_url'], 'Its own poster, no more the provider\'s thumbnail.');
    }

    #[Test]
    public function a_jpeg_thumbnail_is_kept_as_it_is(): void
    {
        Http::fake([self::THUMBNAIL => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/png'])]);

        $output = $this->thumbnail(['provider' => 'youtube', 'thumbnail_url' => self::THUMBNAIL]);

        $this->assertSame('image/jpeg', $output->mime);
        $this->assertSame($this->jpeg(), file_get_contents($output->path));
    }

    #[Test]
    public function another_image_type_becomes_a_jpeg(): void
    {
        $this->requiresImagick();
        $image = new Imagick;
        $image->newImage(4, 3, 'red');
        $image->setImageFormat('png');
        Http::fake([self::THUMBNAIL => Http::response($image->getImageBlob(), 200, ['Content-Type' => 'image/jpeg'])]);

        $output = $this->thumbnail(['provider' => 'youtube', 'thumbnail_url' => self::THUMBNAIL]);

        $this->assertSame('image/jpeg', $output->mime);
        $this->assertSame('image/jpeg', mime_content_type($output->path));
    }

    #[Test]
    public function only_a_thumbnail_of_the_providers_domains_is_downloaded(): void
    {
        Http::fake();

        $this->assertSame(TranscoderErrorCode::UnsupportedInput, $this->failure(fn () => $this->thumbnail(['provider' => 'youtube', 'thumbnail_url' => 'https://i.vimeocdn.com/video/1.jpg'])));
        $this->assertSame(TranscoderErrorCode::UnsupportedInput, $this->failure(fn () => $this->thumbnail(['provider' => 'youtube', 'thumbnail_url' => 'https://169.254.169.254/latest/meta-data'])));
        $this->assertSame(TranscoderErrorCode::ProviderUnavailable, $this->failure(fn () => $this->thumbnail(['provider' => 'instagram'])));
        Http::assertNothingSent();
    }

    #[Test]
    public function a_missing_or_wrong_thumbnail_fails_with_its_code(): void
    {
        Http::fake([
            'i.ytimg.com/vi/missing/*' => Http::response('Not Found', 404),
            'i.ytimg.com/vi/moved/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
            'i.ytimg.com/vi/html/*' => Http::response('<html><body>Not an image</body></html>', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $metadata = fn (string $id) => ['provider' => 'youtube', 'thumbnail_url' => 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg'];

        $this->assertSame(TranscoderErrorCode::ProviderUnavailable, $this->failure(fn () => $this->thumbnail($metadata('missing'))));
        $this->assertSame(TranscoderErrorCode::ProviderFailed, $this->failure(fn () => $this->thumbnail($metadata('moved'))), 'Redirects are not followed.');
        $this->assertSame(TranscoderErrorCode::ProviderFailed, $this->failure(fn () => $this->thumbnail($metadata('html'))));
    }

    #[Test]
    public function the_thumbnail_domains_are_the_providers_and_their_subdomains(): void
    {
        $this->assertTrue(EmbedProviders::isThumbnail('youtube', 'https://i.ytimg.com/vi/a/hqdefault.jpg'));
        $this->assertTrue(EmbedProviders::isThumbnail('tiktok', 'https://p16-sign-va.tiktokcdn.com/obj/a.jpeg?x-expires=1'));
        $this->assertTrue(EmbedProviders::isThumbnail('spotify', 'https://image-cdn-fa.spotifycdn.com/image/ab67'));
        $this->assertFalse(EmbedProviders::isThumbnail('youtube', 'https://evilytimg.com/a.jpg'));
        $this->assertFalse(EmbedProviders::isThumbnail('youtube', 'https://ytimg.com.evil.example/a.jpg'));
        $this->assertFalse(EmbedProviders::isThumbnail('youtube', 'http://i.ytimg.com/a.jpg'));
        $this->assertFalse(EmbedProviders::isThumbnail('youtube', 'https://i.ytimg.com:8443/a.jpg'));
        $this->assertFalse(EmbedProviders::isThumbnail('youtube', 'https://user@i.ytimg.com/a.jpg'));
        $this->assertFalse(EmbedProviders::isThumbnail('unknown', 'https://i.ytimg.com/a.jpg'));
    }
}
