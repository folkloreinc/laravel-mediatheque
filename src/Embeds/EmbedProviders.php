<?php

namespace Folklore\Mediatheque\Embeds;

use Folklore\Mediatheque\Enums\ProbeKind;

/**
 * Recognizes the pages of the embed providers and reads their ids, without
 * any request (docs/decisions/0029). The rules are those of Eclair's
 * providers, so an embed has the same provider, id and canonical URL
 * whichever prober reads it.
 */
final class EmbedProviders
{
    /** Every provider this package recognizes. */
    public const array PROVIDERS = ['youtube', 'vimeo', 'dailymotion', 'soundcloud', 'spotify', 'tiktok', 'instagram'];

    /**
     * The domains each provider serves its thumbnails from: a thumbnail is
     * only downloaded from its provider's, so the URL a provider answers
     * never makes the server connect anywhere else (docs/decisions/0031).
     */
    public const array THUMBNAIL_DOMAINS = [
        'youtube' => ['ytimg.com'],
        'vimeo' => ['vimeocdn.com'],
        'dailymotion' => ['dmcdn.net'],
        'soundcloud' => ['sndcdn.com'],
        'spotify' => ['scdn.co', 'spotifycdn.com'],
        'tiktok' => ['tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokcdn-eu.com'],
        'instagram' => ['cdninstagram.com', 'fbcdn.net'],
    ];

    /**
     * Whether a URL is a thumbnail of a provider: `https`, without
     * credentials or port, on one of the provider's thumbnail domains or
     * their subdomains.
     */
    public static function isThumbnail(string $provider, string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! isset($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);

        foreach (self::THUMBNAIL_DOMAINS[$provider] ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider, among `$allowed`, that recognizes a URL, or null.
     *
     * @param  list<string>  $allowed
     */
    public static function match(string $url, array $allowed = self::PROVIDERS): ?EmbedMatch
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), fn (string $segment) => $segment !== ''));
        parse_str($parts['query'] ?? '', $query);

        foreach ($allowed as $provider) {
            $match = match ($provider) {
                'youtube' => self::youtube($host, $segments, $query),
                'vimeo' => self::vimeo($host, $segments, $query),
                'dailymotion' => self::dailymotion($host, $segments, $query),
                'soundcloud' => self::soundcloud($host, $segments),
                'spotify' => self::spotify($host, $segments),
                'tiktok' => self::tiktok($host, $segments),
                'instagram' => self::instagram($host, $segments),
                default => null,
            };
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<mixed>  $query
     */
    private static function youtube(string $host, array $segments, array $query): ?EmbedMatch
    {
        $id = null;
        if ($host === 'youtu.be') {
            $id = $segments[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            $first = $segments[0] ?? null;
            $id = match (true) {
                $first === 'watch' => is_string($query['v'] ?? null) ? $query['v'] : null,
                in_array($first, ['shorts', 'embed', 'live', 'v'], true) => $segments[1] ?? null,
                default => null,
            };
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1
            ? new EmbedMatch('youtube', $id, 'https://www.youtube.com/watch?v='.$id, ProbeKind::Video)
            : null;
    }

    /**
     * @param  list<string>  $segments
     * @param  array<mixed>  $query
     */
    private static function vimeo(string $host, array $segments, array $query): ?EmbedMatch
    {
        $id = null;
        $hash = null;
        if ($host === 'player.vimeo.com' && ($segments[0] ?? null) === 'video') {
            $id = $segments[1] ?? null;
            $hash = is_string($query['h'] ?? null) ? $query['h'] : null;
        } elseif (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            [$first, $second, $third, $fourth] = [...$segments, null, null, null, null];
            if (is_string($first) && ctype_digit($first)) {
                [$id, $hash] = [$first, $second];
            } elseif ($first === 'channels') {
                $id = $third;
            } elseif (($first === 'groups' && $third === 'videos') || ($first === 'showcase' && $third === 'video')) {
                $id = $fourth;
            }
        }
        if (! is_string($id) || preg_match('/^\d{1,12}$/', $id) !== 1) {
            return null;
        }
        $hash = is_string($hash) && preg_match('/^[0-9a-f]{6,32}$/', $hash) === 1 ? $hash : null;

        return new EmbedMatch('vimeo', $id, 'https://vimeo.com/'.$id.($hash !== null ? '/'.$hash : ''), ProbeKind::Video);
    }

    /**
     * @param  list<string>  $segments
     * @param  array<mixed>  $query
     */
    private static function dailymotion(string $host, array $segments, array $query): ?EmbedMatch
    {
        $id = null;
        if ($host === 'dai.ly') {
            $id = $segments[0] ?? null;
        } elseif ($host === 'geo.dailymotion.com') {
            $id = is_string($query['video'] ?? null) ? $query['video'] : null;
        } elseif (in_array($host, ['dailymotion.com', 'www.dailymotion.com'], true)) {
            $index = ($segments[0] ?? null) === 'embed' ? 1 : 0;
            // Old URLs append a slug: /video/x7tgad0_midnight-sun
            $id = ($segments[$index] ?? null) === 'video' ? explode('_', $segments[$index + 1] ?? '')[0] : null;
        }

        return is_string($id) && preg_match('/^[a-z0-9]{5,20}$/', $id) === 1
            ? new EmbedMatch('dailymotion', $id, 'https://www.dailymotion.com/video/'.$id, ProbeKind::Video)
            : null;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function soundcloud(string $host, array $segments): ?EmbedMatch
    {
        if (! in_array($host, ['soundcloud.com', 'www.soundcloud.com', 'm.soundcloud.com'], true)) {
            return null;
        }
        $user = $segments[0] ?? null;
        $track = $segments[1] ?? null;
        $secret = $segments[2] ?? null;
        $reservedUsers = ['discover', 'stream', 'you', 'search', 'upload', 'charts', 'pages', 'terms-of-use', 'messages', 'notifications', 'settings', 'people', 'tags'];
        $reservedTracks = ['sets', 'tracks', 'albums', 'likes', 'reposts', 'popular-tracks', 'followers', 'following', 'comments'];
        if (
            $user === null || $track === null || count($segments) > 3
            || preg_match('/^[a-z0-9_-]{1,255}$/i', $user) !== 1 || preg_match('/^[a-z0-9_-]{1,255}$/i', $track) !== 1
            || in_array($user, $reservedUsers, true) || in_array($track, $reservedTracks, true)
            || ($secret !== null && preg_match('/^s-[A-Za-z0-9]+$/', $secret) !== 1)
        ) {
            return null;
        }

        return new EmbedMatch('soundcloud', $user.'/'.$track, 'https://soundcloud.com/'.implode('/', array_filter([$user, $track, $secret])), ProbeKind::Audio);
    }

    /**
     * @param  list<string>  $segments
     */
    private static function spotify(string $host, array $segments): ?EmbedMatch
    {
        if ($host !== 'open.spotify.com') {
            return null;
        }
        if (str_starts_with($segments[0] ?? '', 'intl-')) {
            array_shift($segments);
        }
        [$type, $id] = [$segments[0] ?? null, $segments[1] ?? null];

        return in_array($type, ['track', 'episode'], true) && is_string($id) && preg_match('/^[A-Za-z0-9]{22}$/', $id) === 1
            ? new EmbedMatch('spotify', $id, 'https://open.spotify.com/'.$type.'/'.$id, ProbeKind::Audio)
            : null;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function tiktok(string $host, array $segments): ?EmbedMatch
    {
        [$user, $video, $id] = [$segments[0] ?? null, $segments[1] ?? null, $segments[2] ?? null];

        return in_array($host, ['tiktok.com', 'www.tiktok.com', 'm.tiktok.com'], true)
            && is_string($user) && preg_match('/^@[A-Za-z0-9._]{1,64}$/', $user) === 1
            && $video === 'video' && is_string($id) && preg_match('/^\d{5,25}$/', $id) === 1
            ? new EmbedMatch('tiktok', $id, 'https://www.tiktok.com/'.$user.'/video/'.$id, ProbeKind::Video)
            : null;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function instagram(string $host, array $segments): ?EmbedMatch
    {
        if (! in_array($host, ['instagram.com', 'www.instagram.com'], true)) {
            return null;
        }
        // /p/<code>, /reel/<code>, /reels/<code>, /tv/<code>, and the same under /<user>/
        foreach ([0, 1] as $index) {
            $type = $segments[$index] ?? null;
            $code = $segments[$index + 1] ?? null;
            if (in_array($type, ['p', 'reel', 'reels', 'tv'], true) && is_string($code) && preg_match('/^[A-Za-z0-9_-]{5,64}$/', $code) === 1) {
                $reel = $type !== 'p';

                return new EmbedMatch('instagram', $code, 'https://www.instagram.com/'.($reel ? 'reel' : 'p').'/'.$code.'/', $reel ? ProbeKind::Video : ProbeKind::Other);
            }
        }

        return null;
    }
}
