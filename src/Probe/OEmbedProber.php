<?php

namespace Folklore\Mediatheque\Probe;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Embeds\EmbedMatch;
use Folklore\Mediatheque\Embeds\EmbedProviders;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;

/**
 * Probes an embed without credentials, for applications without Eclair
 * (docs/decisions/0029): the provider's oEmbed, or Dailymotion's public API.
 * It requests the provider's endpoint only, never the page itself, so there
 * is no discovery and no request to an address the URL chooses.
 *
 * What oEmbed doesn't give stays out: YouTube's duration, the author's id of
 * most providers. Instagram's oEmbed needs a Meta token: its embeds fail
 * here, and Eclair's prober reads them.
 */
class OEmbedProber implements Prober
{
    public function __construct(
        private readonly Http $http,
        private readonly MediathequeConfig $config,
    ) {}

    public function probe(File $file): ProbeOutcome
    {
        $url = $file->metadata(MetadataKey::ProviderUrl->value);
        $match = is_string($url) ? EmbedProviders::match($url) : null;

        if ($match === null || $match->provider !== $file->provider()) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::UnsupportedInput, 'The file is not an embed of a known provider.'));
        }

        try {
            $metadata = match ($match->provider) {
                'youtube' => $this->youtube($match),
                'vimeo' => $this->vimeo($match),
                'dailymotion' => $this->dailymotion($match),
                'soundcloud' => $this->oembed($match, 'https://soundcloud.com/oembed', ['format' => 'json', 'maxheight' => '166']),
                'spotify' => $this->oembed($match, 'https://open.spotify.com/oembed', [], 'iframe_url'),
                'tiktok' => $this->oembed($match, 'https://www.tiktok.com/oembed', []),
                default => throw new ProviderError(TranscoderErrorCode::ProviderFailed, sprintf('Probing %s embeds needs credentials: probe them with Eclair.', $match->provider)),
            };
        } catch (ProviderError $error) {
            return ProbeOutcome::failed(new TranscoderError($error->errorCode, $error->getMessage()));
        } catch (ConnectionException $exception) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::ProviderFailed, sprintf('%s didn\'t answer: %s', $match->provider, $exception->getMessage())));
        }

        return ProbeOutcome::done(array_filter([
            MetadataKey::Kind->value => $match->kind->value,
            MetadataKey::Mime->value => 'text/html',
            MetadataKey::Provider->value => $match->provider,
            MetadataKey::ProviderId->value => $match->id,
            MetadataKey::ProviderUrl->value => $match->url,
            ...$metadata,
        ], fn (mixed $value) => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function youtube(EmbedMatch $match): array
    {
        $body = $this->get($match, 'https://www.youtube.com/oembed', ['url' => $match->url, 'format' => 'json', 'maxwidth' => '1920', 'maxheight' => '1080']);

        return [
            ...$this->fromOEmbed($body),
            MetadataKey::EmbedUrl->value => 'https://www.youtube.com/embed/'.$match->id,
            // It answers for videos other sites may play
            MetadataKey::Embeddable->value => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vimeo(EmbedMatch $match): array
    {
        $body = $this->get($match, 'https://vimeo.com/api/oembed.json', ['url' => $match->url, 'width' => '1920']);
        $hash = explode('/', (string) parse_url($match->url, PHP_URL_PATH))[2] ?? null;

        return [
            ...$this->fromOEmbed($body),
            MetadataKey::Duration->value => self::number($body['duration'] ?? null),
            MetadataKey::Description->value => self::string($body['description'] ?? null),
            MetadataKey::EmbedUrl->value => 'https://player.vimeo.com/video/'.$match->id.($hash !== null && $hash !== '' ? '?h='.$hash : ''),
            MetadataKey::Embeddable->value => true,
        ];
    }

    /**
     * Dailymotion's public API: everything but the dimensions.
     *
     * @return array<string, mixed>
     */
    private function dailymotion(EmbedMatch $match): array
    {
        $body = $this->get($match, 'https://api.dailymotion.com/video/'.$match->id, [
            'fields' => 'title,description,duration,thumbnail_url,owner.id,owner.screenname,owner.url,created_time,allow_embed,private,mode,embed_url',
        ]);
        $created = $body['created_time'] ?? null;
        $private = $body['private'] ?? null;
        $mode = $body['mode'] ?? null;

        return [
            MetadataKey::Duration->value => self::number($body['duration'] ?? null),
            MetadataKey::Title->value => self::string($body['title'] ?? null),
            MetadataKey::Description->value => self::string($body['description'] ?? null),
            MetadataKey::AuthorId->value => self::string($body['owner.id'] ?? null),
            MetadataKey::AuthorName->value => self::string($body['owner.screenname'] ?? null),
            MetadataKey::AuthorUrl->value => self::https($body['owner.url'] ?? null),
            MetadataKey::ThumbnailUrl->value => self::https($body['thumbnail_url'] ?? null),
            MetadataKey::EmbedUrl->value => self::https($body['embed_url'] ?? null) ?? 'https://geo.dailymotion.com/player.html?video='.$match->id,
            MetadataKey::PublishedAt->value => is_int($created) ? gmdate('Y-m-d\TH:i:s\Z', $created) : null,
            MetadataKey::Embeddable->value => is_bool($body['allow_embed'] ?? null) ? $body['allow_embed'] : null,
            // Readable through its private id only: unlisted
            MetadataKey::Privacy->value => is_bool($private) ? ($private ? 'unlisted' : 'public') : null,
            MetadataKey::Live->value => is_string($mode) ? $mode === 'live' : null,
        ];
    }

    /**
     * A provider's oEmbed, with the player's URL from its HTML (or `$playerKey`).
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function oembed(EmbedMatch $match, string $endpoint, array $query, ?string $playerKey = null): array
    {
        $body = $this->get($match, $endpoint, ['url' => $match->url, ...$query]);
        $player = $playerKey !== null ? self::https($body[$playerKey] ?? null) : null;

        return [
            ...$this->fromOEmbed($body),
            MetadataKey::Description->value => self::string($body['description'] ?? null),
            MetadataKey::EmbedUrl->value => $player ?? self::iframeSource($body['html'] ?? null),
            MetadataKey::Embeddable->value => true,
        ];
    }

    /**
     * What every oEmbed answer has.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<string, mixed>
     */
    private function fromOEmbed(array $body): array
    {
        return [
            MetadataKey::Title->value => self::string($body['title'] ?? null),
            MetadataKey::AuthorName->value => self::string($body['author_name'] ?? null),
            MetadataKey::AuthorUrl->value => self::https($body['author_url'] ?? null),
            MetadataKey::ThumbnailUrl->value => self::https($body['thumbnail_url'] ?? null),
            MetadataKey::Width->value => self::positiveInt($body['width'] ?? null),
            MetadataKey::Height->value => self::positiveInt($body['height'] ?? null),
            MetadataKey::EmbedHtml->value => self::string($body['html'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<array-key, mixed>
     */
    private function get(EmbedMatch $match, string $endpoint, array $query): array
    {
        $response = $this->http->timeout($this->config->embedTimeout())->acceptJson()->get($endpoint, $query);

        if (! $response->successful()) {
            throw self::statusError($match, $response);
        }

        $body = $response->json();

        return is_array($body) ? $body : throw new ProviderError(TranscoderErrorCode::ProviderFailed, sprintf('%s didn\'t answer JSON.', $match->provider));
    }

    private static function statusError(EmbedMatch $match, Response $response): ProviderError
    {
        $status = $response->status();

        return match (true) {
            // YouTube answers 400 for an id it doesn't know
            in_array($status, [400, 404, 410], true) => new ProviderError(TranscoderErrorCode::ProviderUnavailable, sprintf('%s has no such video or audio.', $match->provider)),
            in_array($status, [401, 403], true) => new ProviderError(TranscoderErrorCode::ProviderUnavailable, sprintf('%s doesn\'t let other sites read it: it is private, or can\'t be embedded.', $match->provider)),
            $status === 429 => new ProviderError(TranscoderErrorCode::ProviderRefused, sprintf('%s limits the requests.', $match->provider)),
            default => new ProviderError(TranscoderErrorCode::ProviderFailed, sprintf('%s answered %d.', $match->provider, $status)),
        };
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function https(mixed $value): ?string
    {
        $value = self::string($value);
        if ($value !== null && str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        }

        return $value !== null && str_starts_with($value, 'https://') ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = is_string($value) && ctype_digit($value) ? (int) $value : $value;

        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function number(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? $value : null;
    }

    private static function iframeSource(mixed $html): ?string
    {
        return is_string($html) && preg_match('/<iframe[^>]*\ssrc="([^"]+)"/i', $html, $matches) === 1
            ? self::https(html_entity_decode($matches[1]))
            : null;
    }
}
