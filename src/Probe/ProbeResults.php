<?php

namespace Folklore\Mediatheque\Probe;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaProbed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores the outcome of a media's probe (docs/decisions/0012, 0014): from
 * the probe job, or from the recorder when a pending probe completes.
 *
 * - Done: the metadata goes to the media, the dimensions and duration to its
 *   original, with an image's EXIF orientation in its metadata, and the media moves to `processing` (`MediaProbed`), or to
 *   `ready` (`MediaReady`) when its pipeline has no main rendition. Then its
 *   pipeline starts: the one ingest chose, or its type's.
 * - Failed: the media is `failed` (`MediaFailed`).
 *
 * Only a `pending` media is updated, so a repeated probe changes nothing.
 */
class ProbeResults
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly MediathequeConfig $config,
        private readonly PipelineRunner $runner,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Canonical keys, with a kind
     * @param  string|null  $pipeline  The pipeline ingest chose; the type's by default
     */
    public function done(Model&Media $media, array $metadata, ?string $pipeline = null): void
    {
        if ($media->status() !== MediaStatus::Pending) {
            return;
        }

        $original = $media->original();
        if ($original instanceof Model) {
            $orientation = self::int($metadata[MetadataKey::Orientation->value] ?? null);

            $original->forceFill(array_filter([
                'width' => self::int($metadata[MetadataKey::Width->value] ?? null),
                'height' => self::int($metadata[MetadataKey::Height->value] ?? null),
                'duration' => self::float($metadata[MetadataKey::Duration->value] ?? null),
                'bitrate' => self::int($metadata[MetadataKey::Bitrate->value] ?? null),
                'metadata' => $orientation !== null ? [...$original->metadata(), MetadataKey::Orientation->value => $orientation] : null,
            ], fn (int|float|array|null $value) => $value !== null))->save();
        }

        $definition = $pipeline !== null ? $this->config->pipeline($pipeline) : $this->config->pipelineOfType($media->type());
        $ready = $definition?->mainRendition() === null;

        $media->forceFill([
            'metadata' => [...$media->metadata(), ...$metadata],
            'status' => MediaStatus::Processing,
        ])->save();

        $this->events->dispatch(new MediaProbed($media, $metadata));

        if ($ready && $media->status() === MediaStatus::Processing) {
            $media->forceFill(['status' => MediaStatus::Ready])->save();
            $this->events->dispatch(new MediaReady($media));
        }

        if ($definition !== null) {
            $this->runner->run($media, $definition->name);
        }
    }

    public function failed(Model&Media $media, TranscoderError $error): void
    {
        if ($media->status() !== MediaStatus::Pending) {
            return;
        }

        $media->forceFill(['status' => MediaStatus::Failed])->save();
        $this->events->dispatch(new MediaFailed($media, $error));
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
