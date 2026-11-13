<?php

namespace Folklore\Mediatheque\Embeds;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\EmbedRefreshed;
use Folklore\Mediatheque\Events\EmbedUnavailable;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * Stores the outcome of an embed's refresh (docs/decisions/0031), as
 * ProbeResults stores a first probe's, without ever changing the media's
 * status:
 *
 * - Done: what the provider says now replaces what it said, the original
 *   gets the new dimensions and duration, `unavailable_at` goes, and
 *   `EmbedRefreshed` is dispatched. When the provider's thumbnail changed,
 *   or the embed has no poster, the embeds' pipeline runs again.
 * - Unavailable (`provider_unavailable`): `unavailable_at` is set, once,
 *   and `EmbedUnavailable` is dispatched.
 * - Any other failure (a quota, a provider down) changes nothing: the run
 *   records it, and the next refresh tries again.
 *
 * @internal
 */
final class EmbedRefreshes
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly MediathequeConfig $config,
        private readonly PipelineRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Canonical keys, with a kind
     */
    public function done(Model&Media $media, array $metadata): void
    {
        if (! $media->isEmbed()) {
            return;
        }

        $previous = $media->metadata(MetadataKey::ThumbnailUrl->value);
        $original = $media->original();
        if ($original instanceof Model) {
            $original->forceFill(array_filter([
                'width' => is_int($metadata[MetadataKey::Width->value] ?? null) ? $metadata[MetadataKey::Width->value] : null,
                'height' => is_int($metadata[MetadataKey::Height->value] ?? null) ? $metadata[MetadataKey::Height->value] : null,
                'duration' => is_int($metadata[MetadataKey::Duration->value] ?? null) || is_float($metadata[MetadataKey::Duration->value] ?? null) ? (float) $metadata[MetadataKey::Duration->value] : null,
            ], fn (int|float|null $value) => $value !== null))->save();
        }

        $refreshed = [...$media->metadata(), ...$metadata];
        unset($refreshed[MetadataKey::UnavailableAt->value]);
        $media->forceFill(['metadata' => $refreshed])->save();
        $this->events->dispatch(new EmbedRefreshed($media, $metadata));

        $pipeline = $this->config->embedPipeline();
        $thumbnail = $metadata[MetadataKey::ThumbnailUrl->value] ?? null;
        if ($pipeline !== null && $pipeline->appliesTo($refreshed) && ($media->thumbnail() === null || (is_string($thumbnail) && self::changed($previous, $thumbnail)))) {
            $this->runner->run($media, $pipeline->name, force: true);
        }
    }

    public function failed(Model&Media $media, TranscoderError $error): void
    {
        if (! $media->isEmbed() || $error->code !== TranscoderErrorCode::ProviderUnavailable || $media->metadata(MetadataKey::UnavailableAt->value) !== null) {
            return;
        }

        $media->forceFill(['metadata' => [...$media->metadata(), MetadataKey::UnavailableAt->value => Date::now()->toIso8601String()]])->save();
        $this->events->dispatch(new EmbedUnavailable($media, $error));
    }

    /**
     * Whether the thumbnail is another image: its URL without the query,
     * which some providers sign and change at each request.
     */
    private static function changed(mixed $previous, string $current): bool
    {
        return ! is_string($previous) || explode('?', $previous, 2)[0] !== explode('?', $current, 2)[0];
    }
}
