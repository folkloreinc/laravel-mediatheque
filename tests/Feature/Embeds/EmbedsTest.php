<?php

namespace Folklore\Mediatheque\Tests\Feature\Embeds;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Http\Resources\MediaResource;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Embeds, end to end with the local prober (docs/decisions/0029): ingest,
 * the probe through the provider's oEmbed, and what applications read.
 */
class EmbedsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([ProbeMedia::class, RunRendition::class]);
        Http::preventStrayRequests();
    }

    /**
     * @return array<mixed>
     */
    private static function answer(string $name): array
    {
        $json = json_decode((string) file_get_contents(self::fixturePath('embeds/'.$name.'.json')), true);

        return is_array($json) ? $json : [];
    }

    private function probe(MediaContract $ingested): Media
    {
        $media = Media::query()->where('uid', $ingested->uid())->firstOrFail();
        app()->call([new ProbeMedia($media->id), 'handle']);

        return Media::query()->findOrFail($media->id);
    }

    #[Test]
    public function an_embed_is_a_media_whose_original_is_its_page(): void
    {
        $media = Mediatheque::ingest(Source::embed('https://player.vimeo.com/video/76979871?h=8272103f6e'));

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame('video', $media->type());
        $this->assertSame(MediaStatus::Pending, $media->status());
        $this->assertSame('https://vimeo.com/76979871/8272103f6e', $media->name());
        $this->assertSame([
            'provider' => 'vimeo',
            'provider_id' => '76979871',
            'provider_url' => 'https://vimeo.com/76979871/8272103f6e',
        ], $media->metadata());

        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertTrue($original->isRemote());
        $this->assertSame(['vimeo', '76979871', null, null], [$original->provider(), $original->providerRef(), $original->source(), $original->path()]);
        $this->assertTrue($media->isEmbed());
        // Nothing is stored or requested at ingest: the probe reads the provider
        $this->assertSame([], Storage::disk('public')->allFiles());
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id);
    }

    #[Test]
    public function a_probed_embed_is_ready_with_what_its_provider_says_and_runs_the_embed_pipeline(): void
    {
        Event::fake([MediaReady::class]);
        Http::fake(['vimeo.com/api/oembed.json*' => Http::response(self::answer('vimeo-oembed'))]);
        $media = Mediatheque::ingest(Source::embed('https://vimeo.com/76979871'));

        $media = $this->probe($media);

        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame('video', $media->metadata('kind'));
        $this->assertSame(62, $media->metadata('duration'));
        $this->assertSame('The New Vimeo Player (You Know, For Videos)', $media->metadata('title'));
        $this->assertSame('Vimeo', $media->metadata('author_name'));
        $this->assertSame('https://player.vimeo.com/video/76979871', $media->embedUrl());
        $this->assertSame([1920, 1080, 62.0], [$media->original()?->width(), $media->original()?->height(), $media->original()?->duration()]);
        Event::assertDispatched(MediaReady::class);
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://vimeo.com/api/oembed.json?') && $request['url'] === 'https://vimeo.com/76979871');
        // The embeds' pipeline, not the type's, which needs a file: its poster starts from the thumbnail
        $this->assertSame(['probe', 'embed'], PipelineRun::query()->orderBy('id')->pluck('name')->all());
        Bus::assertDispatched(RunRendition::class, fn (RunRendition $job) => $job->rendition === 'thumbnail');
        Bus::assertNotDispatched(RunRendition::class, fn (RunRendition $job) => $job->rendition === 'poster');

        $json = (new MediaResource($media))->toArray(new Request);
        $this->assertSame(['provider' => 'vimeo', 'url' => 'https://vimeo.com/76979871', 'embed_url' => 'https://player.vimeo.com/video/76979871', 'available' => true], $json['embed']);
        $this->assertSame(self::answer('vimeo-oembed')['thumbnail_url'], $json['thumbnail_url']);
        $this->assertNull($json['url']);
    }

    #[Test]
    public function an_audio_provider_gives_an_audio_media(): void
    {
        Http::fake(['soundcloud.com/oembed*' => Http::response(self::answer('soundcloud-oembed'))]);

        $media = $this->probe(Mediatheque::ingest(Source::embed('https://soundcloud.com/forss/flickermood')));

        $this->assertSame('audio', $media->type());
        $this->assertSame('audio', $media->metadata('kind'));
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame('https://w.soundcloud.com/player/?visual=true&url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F293&show_artwork=true&maxheight=166', $media->embedUrl());
    }

    #[Test]
    public function a_missing_embed_fails_its_media(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('Bad Request', 400)]);

        $media = $this->probe(Mediatheque::ingest(Source::embed('https://youtu.be/aqz-KE-bpKQ')));

        $this->assertSame(MediaStatus::Failed, $media->status());
        $this->assertSame(TranscoderErrorCode::ProviderUnavailable, $media->failure()?->code);
    }

    #[Test]
    public function only_the_pages_of_the_configured_providers_are_ingested(): void
    {
        Event::fake([IngestRejected::class]);
        config(['mediatheque.embeds.providers' => ['youtube']]);

        foreach (['https://vimeo.com/76979871', 'https://example.com/video/1'] as $url) {
            try {
                Mediatheque::ingest(Source::embed($url));
                $this->fail(sprintf('%s was ingested.', $url));
            } catch (FileRejected $exception) {
                $this->assertSame(RejectionReason::EmbedNotAllowed, $exception->reason);
            }
        }

        $this->assertSame(0, Media::query()->count());
        Event::assertDispatchedTimes(IngestRejected::class, 2);
        Bus::assertNotDispatched(ProbeMedia::class);
    }

    #[Test]
    public function the_fake_ingests_and_probes_embeds_too(): void
    {
        $fake = Mediatheque::fake();

        $media = Mediatheque::ingest(Source::embed('https://www.tiktok.com/@scout2015/video/6718335390845095173'));

        $this->assertTrue($media->isEmbed());
        $this->assertSame('video', $media->type());
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame('tiktok', $media->original()?->provider());
        $this->assertSame('https://www.tiktok.com/@scout2015/video/6718335390845095173#player', $media->embedUrl());
        $this->assertCount(1, $fake->ingested());

        $this->expectException(FileRejected::class);
        Mediatheque::ingest(Source::embed('https://example.com/video/1'));
    }
}
