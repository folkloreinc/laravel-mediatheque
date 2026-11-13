<?php

namespace Folklore\Mediatheque\Tests\Feature\Embeds;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Probe\OEmbedProber;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class OEmbedProberTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ProbeMedia::class]);
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

    private function probe(string $url): ProbeOutcome
    {
        $original = Mediatheque::ingest(Source::embed($url))->original();
        $this->assertNotNull($original);

        return app(OEmbedProber::class)->probe($original);
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, mixed>}>
     */
    public static function providers(): iterable
    {
        yield 'YouTube' => ['https://youtu.be/aqz-KE-bpKQ', 'www.youtube.com/oembed*', 'youtube-oembed', [
            'kind' => 'video',
            'provider' => 'youtube',
            'provider_id' => 'aqz-KE-bpKQ',
            'provider_url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
            'title' => 'Big Buck Bunny 60fps 4K - Official Blender Foundation Short Film',
            'author_name' => 'Blender',
            'author_url' => 'https://www.youtube.com/@BlenderOfficial',
            'thumbnail_url' => 'https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg',
            'width' => 1920,
            'height' => 1080,
            'embed_url' => 'https://www.youtube.com/embed/aqz-KE-bpKQ',
            'embeddable' => true,
        ]];
        yield 'Dailymotion' => ['https://dai.ly/x7tgad0', 'api.dailymotion.com/video/x7tgad0*', 'dailymotion-api', [
            'kind' => 'video',
            'duration' => 87,
            'title' => 'Midnight Sun | Iceland',
            'author_id' => 'x2djcgk',
            'author_name' => 'Dailymotion player demo',
            'embed_url' => 'https://geo.dailymotion.com/player.html?video=x7tgad0',
            'published_at' => '2020-04-22T15:59:41Z',
            'embeddable' => true,
            'privacy' => 'public',
            'live' => false,
        ]];
        yield 'Spotify' => ['https://open.spotify.com/track/6rqhFgbbKwnb9MLmUQDhG6', 'open.spotify.com/oembed*', 'spotify-oembed', [
            'kind' => 'audio',
            'title' => 'Speak To Me - 2011 Remastered Version',
            'embed_url' => 'https://open.spotify.com/embed/track/6rqhFgbbKwnb9MLmUQDhG6?utm_source=oembed',
        ]];
        yield 'TikTok' => ['https://www.tiktok.com/@scout2015/video/6718335390845095173', 'www.tiktok.com/oembed*', 'tiktok-oembed', [
            'kind' => 'video',
            'embed_url' => 'https://www.tiktok.com/player/v1/6718335390845095173?embed_from=oembed&music_info=1&description=1&autoplay=1&muted=1',
        ]];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[Test]
    #[DataProvider('providers')]
    public function it_reads_what_each_provider_gives_without_credentials(string $url, string $endpoint, string $answer, array $expected): void
    {
        Http::fake([$endpoint => Http::response(self::answer($answer))]);

        $outcome = $this->probe($url);

        $this->assertTrue($outcome->isDone());
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $outcome->metadata[$key] ?? null, $key);
        }
        $this->assertSame('text/html', $outcome->metadata['mime']);
        // Only the provider's endpoint, never the page
        Http::assertSentCount(1);
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === $url);
    }

    #[Test]
    public function it_tells_a_missing_embed_from_a_refusal(): void
    {
        Http::fakeSequence('vimeo.com/api/oembed.json*')
            ->push('', 404)
            ->push('', 403)
            ->push('', 429)
            ->push('', 500)
            ->push('not json', 200);

        $codes = [];
        for ($i = 0; $i < 5; $i++) {
            $codes[] = $this->probe('https://vimeo.com/76979871')->error?->code;
        }

        $this->assertSame([
            TranscoderErrorCode::ProviderUnavailable,
            TranscoderErrorCode::ProviderUnavailable,
            TranscoderErrorCode::ProviderRefused,
            TranscoderErrorCode::ProviderFailed,
            TranscoderErrorCode::ProviderFailed,
        ], $codes);
    }

    #[Test]
    public function a_provider_that_doesnt_answer_fails_the_probe(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertSame(TranscoderErrorCode::ProviderFailed, $this->probe('https://vimeo.com/76979871')->error?->code);
    }

    #[Test]
    public function instagram_needs_eclair(): void
    {
        $outcome = $this->probe('https://www.instagram.com/reel/C0abcdefGhI/');

        $this->assertSame(TranscoderErrorCode::ProviderFailed, $outcome->error?->code);
        Http::assertNothingSent();
    }
}
