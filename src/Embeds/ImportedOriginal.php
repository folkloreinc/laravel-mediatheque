<?php

namespace Folklore\Mediatheque\Embeds;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\ImportMode;
use Folklore\Mediatheque\Events\PipelineCompleted;
use Folklore\Mediatheque\Ingest\OriginalReplacer;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Models\PipelineRun;
use Illuminate\Database\Eloquent\Model;

/**
 * Replaces an embed's remote original with the file its import downloaded,
 * once the import's run completed in `ImportMode::ReplaceOriginal`
 * (docs/decisions/0030). The media becomes an ordinary video or audio, and
 * keeps where it came from in its metadata: `provider`, `provider_id` and
 * `provider_url`.
 *
 * @internal
 */
final class ImportedOriginal
{
    public function __construct(
        private readonly OriginalReplacer $replacer,
    ) {}

    public function handle(PipelineCompleted $event): void
    {
        $run = $event->run;
        $import = $run instanceof PipelineRun ? $run->embedImport() : null;
        if ($import === null || $import['mode'] !== ImportMode::ReplaceOriginal->value) {
            return;
        }

        $media = $run->media();
        // Already replaced, by a duplicate event or another import
        if (! $media instanceof Model || ! $media->isEmbed()) {
            return;
        }

        $file = $media->file($import['rendition']);
        $source = $file?->source();
        $path = $file?->path();
        if ($source === null || $path === null) {
            return;
        }

        $provenance = array_intersect_key($media->metadata(), array_flip([
            MetadataKey::Provider->value,
            MetadataKey::ProviderId->value,
            MetadataKey::ProviderUrl->value,
        ]));

        $this->replacer->replace($media, Source::disk($source, $path, $media->name()), allowTypeChange: true);

        self::keep($media, $provenance);
    }

    /**
     * Put the embed's provenance back in the media's metadata, which the
     * replacement reset, keeping what the probe may have written since.
     *
     * @param  array<string, mixed>  $provenance
     */
    private static function keep(Model&Media $media, array $provenance): void
    {
        $media->refresh();
        $media->forceFill(['metadata' => [...$media->metadata(), ...$provenance]])->save();
    }
}
