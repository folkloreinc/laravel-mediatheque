<?php

namespace Folklore\Mediatheque\Tests\Unit\Embeds;

use Folklore\Mediatheque\Embeds\EmbedProviders;
use Folklore\Mediatheque\Enums\ProbeKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EmbedProvidersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string, ProbeKind}>
     */
    public static function pages(): iterable
    {
        yield 'YouTube watch' => ['https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=10s', 'youtube', 'aqz-KE-bpKQ', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', ProbeKind::Video];
        yield 'YouTube short link' => ['https://youtu.be/aqz-KE-bpKQ?si=abc', 'youtube', 'aqz-KE-bpKQ', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', ProbeKind::Video];
        yield 'YouTube short' => ['https://m.youtube.com/shorts/aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', ProbeKind::Video];
        yield 'YouTube embed' => ['https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', ProbeKind::Video];
        yield 'Vimeo' => ['https://vimeo.com/76979871', 'vimeo', '76979871', 'https://vimeo.com/76979871', ProbeKind::Video];
        yield 'Vimeo unlisted' => ['https://vimeo.com/76979871/8272103f6e', 'vimeo', '76979871', 'https://vimeo.com/76979871/8272103f6e', ProbeKind::Video];
        yield 'Vimeo player' => ['https://player.vimeo.com/video/76979871?h=8272103f6e&badge=0', 'vimeo', '76979871', 'https://vimeo.com/76979871/8272103f6e', ProbeKind::Video];
        yield 'Vimeo channel' => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871', 'https://vimeo.com/76979871', ProbeKind::Video];
        yield 'Dailymotion' => ['https://www.dailymotion.com/video/x7tgad0_midnight-sun', 'dailymotion', 'x7tgad0', 'https://www.dailymotion.com/video/x7tgad0', ProbeKind::Video];
        yield 'Dailymotion short link' => ['https://dai.ly/x7tgad0', 'dailymotion', 'x7tgad0', 'https://www.dailymotion.com/video/x7tgad0', ProbeKind::Video];
        yield 'Dailymotion player' => ['https://geo.dailymotion.com/player.html?video=x7tgad0', 'dailymotion', 'x7tgad0', 'https://www.dailymotion.com/video/x7tgad0', ProbeKind::Video];
        yield 'SoundCloud' => ['https://soundcloud.com/forss/flickermood', 'soundcloud', 'forss/flickermood', 'https://soundcloud.com/forss/flickermood', ProbeKind::Audio];
        yield 'SoundCloud private' => ['https://soundcloud.com/forss/flickermood/s-AbC123', 'soundcloud', 'forss/flickermood', 'https://soundcloud.com/forss/flickermood/s-AbC123', ProbeKind::Audio];
        yield 'Spotify track' => ['https://open.spotify.com/track/6rqhFgbbKwnb9MLmUQDhG6?si=x', 'spotify', '6rqhFgbbKwnb9MLmUQDhG6', 'https://open.spotify.com/track/6rqhFgbbKwnb9MLmUQDhG6', ProbeKind::Audio];
        yield 'Spotify episode' => ['https://open.spotify.com/intl-fr/episode/512ojhOuo1ktJprKbVcKyQ', 'spotify', '512ojhOuo1ktJprKbVcKyQ', 'https://open.spotify.com/episode/512ojhOuo1ktJprKbVcKyQ', ProbeKind::Audio];
        yield 'TikTok' => ['https://www.tiktok.com/@scout2015/video/6718335390845095173?lang=fr', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/@scout2015/video/6718335390845095173', ProbeKind::Video];
        yield 'Instagram post' => ['https://www.instagram.com/p/C0abcdefGhI/', 'instagram', 'C0abcdefGhI', 'https://www.instagram.com/p/C0abcdefGhI/', ProbeKind::Other];
        yield 'Instagram reel of a user' => ['https://www.instagram.com/someone/reel/C0abcdefGhI/?igsh=x', 'instagram', 'C0abcdefGhI', 'https://www.instagram.com/reel/C0abcdefGhI/', ProbeKind::Video];
    }

    #[Test]
    #[DataProvider('pages')]
    public function it_recognizes_the_pages_of_the_providers(string $url, string $provider, string $id, string $canonical, ProbeKind $kind): void
    {
        $match = EmbedProviders::match($url);

        $this->assertNotNull($match);
        $this->assertSame([$provider, $id, $canonical, $kind], [$match->provider, $match->id, $match->url, $match->kind]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function others(): iterable
    {
        yield 'a lookalike host' => ['https://www.youtube.com.evil.example/watch?v=aqz-KE-bpKQ'];
        yield 'a page on another host' => ['https://evil.example/https://www.youtube.com/watch?v=aqz-KE-bpKQ'];
        yield 'plain http' => ['http://www.youtube.com/watch?v=aqz-KE-bpKQ'];
        yield 'credentials' => ['https://user:secret@vimeo.com/76979871'];
        yield 'a short id' => ['https://www.youtube.com/watch?v=too-short'];
        yield 'a channel' => ['https://www.youtube.com/@BlenderOfficial'];
        yield 'a playlist' => ['https://www.youtube.com/playlist?list=PL123'];
        yield 'a Vimeo user' => ['https://vimeo.com/staff'];
        yield 'a SoundCloud user' => ['https://soundcloud.com/forss'];
        yield 'a SoundCloud set' => ['https://soundcloud.com/forss/sets/an-album'];
        yield 'a Spotify album' => ['https://open.spotify.com/album/4LH4d3cOWNNsVw41Gqt2kv'];
        yield 'a TikTok profile' => ['https://www.tiktok.com/@scout2015'];
        yield 'an Instagram story' => ['https://www.instagram.com/stories/someone/123/'];
    }

    #[Test]
    #[DataProvider('others')]
    public function it_recognizes_nothing_else(string $url): void
    {
        $this->assertNull(EmbedProviders::match($url));
    }

    #[Test]
    public function it_only_recognizes_the_providers_it_is_given(): void
    {
        $this->assertNull(EmbedProviders::match('https://vimeo.com/76979871', ['youtube']));
        $this->assertSame('vimeo', EmbedProviders::match('https://vimeo.com/76979871', ['youtube', 'vimeo'])?->provider);
    }
}
