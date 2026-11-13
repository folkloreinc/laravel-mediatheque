<?php

namespace Folklore\Mediatheque\Tests\Feature\Embeds;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\ImportMode;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Importing an embed's file (docs/decisions/0030): its import pipeline, and
 * what each mode makes of the file. The transcoder of the test holds its runs,
 * which the test completes as Eclair's webhook would.
 */
class ImportEmbedTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([ProbeMedia::class, DeleteStoredFiles::class]);

        app(TranscoderManager::class)->extend('import-test', fn () => new class implements Transcoder
        {
            public function supports(string $operation): bool
            {
                return true;
            }

            public function capabilities(): Capabilities
            {
                return Capabilities::none();
            }

            public function submit(PipelineRunContract $run): ?string
            {
                return null;
            }

            public function cancel(PipelineRunContract $run): void {}

            public function refresh(PipelineRunContract $run): void {}
        });
        config([
            'mediatheque.pipelines.embed_import' => ['transcoder' => 'import-test', 'renditions' => ['download' => ['op' => 'embed.download']]],
            'mediatheque.embeds.import_pipeline' => 'embed_import',
        ]);
    }

    /**
     * A probed embed, ready, as an application holds it.
     */
    private function embed(): Media
    {
        $ingested = Mediatheque::ingest(Source::embed('https://vimeo.com/76979871'));
        $media = Media::query()->where('uid', $ingested->uid())->firstOrFail();
        $media->forceFill([
            'status' => MediaStatus::Ready,
            'metadata' => [...$media->metadata(), 'kind' => 'video', 'title' => 'The New Vimeo Player', 'embed_url' => 'https://player.vimeo.com/video/76979871'],
        ])->save();

        return $media;
    }

    /**
     * Completes the import's download as Eclair would: a real video, written
     * under the run's prefix.
     */
    private function completeDownload(PipelineRunContract $run): string
    {
        $path = $run->pathPrefix().'/download/video.mp4';
        $contents = (string) file_get_contents(self::fixturePath('media/test.mp4'));
        Storage::disk($run->outputSource())->put($path, $contents);
        $job = $run->job('download');
        $this->assertNotNull($job);
        $recorder = app(Recorder::class);
        $recorder->started($job);
        $recorder->completed($job, new Outputs([new OutputFile(path: $path, mime: 'video/mp4', size: strlen($contents))]));

        return $contents;
    }

    private function fresh(MediaContract $media): Media
    {
        return Media::query()->where('uid', $media->uid())->firstOrFail();
    }

    #[Test]
    public function the_file_can_stay_a_rendition_of_the_embed(): void
    {
        $media = $this->embed();

        $run = Mediatheque::importEmbed($media);

        $this->assertNotNull($run);
        $this->assertSame('embed_import', $run->name());
        $this->completeDownload($run);

        $media = $this->fresh($media);
        $this->assertTrue($media->isEmbed());
        $this->assertSame('https://player.vimeo.com/video/76979871', $media->embedUrl());
        $download = $media->file('download');
        $this->assertNotNull($download);
        $this->assertSame('video/mp4', $download->mime());
        Bus::assertNotDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $media->original()?->isRemote() === false);
    }

    #[Test]
    public function the_file_can_replace_the_embeds_original(): void
    {
        $media = $this->embed();

        $run = Mediatheque::importEmbed($media, ImportMode::ReplaceOriginal);

        $this->assertNotNull($run);
        $contents = $this->completeDownload($run);

        $media = $this->fresh($media);
        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertFalse($original->isRemote());
        $this->assertFalse($media->isEmbed());
        $this->assertSame(['video', 'video/mp4', MediaStatus::Pending], [$media->type(), $original->mime(), $media->status()]);
        $this->assertSame($contents, Storage::disk((string) $original->source())->get((string) $original->path()));
        // Where it came from stays; what the provider said goes, until the probe
        $this->assertSame(['vimeo', '76979871', 'https://vimeo.com/76979871'], [
            $media->metadata('provider'),
            $media->metadata('provider_id'),
            $media->metadata('provider_url'),
        ]);
        $this->assertNull($media->metadata('embed_url'));
        $this->assertNull($media->file('download'));
        // Probed again, then its type's pipeline
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === null);
    }

    #[Test]
    public function a_retried_import_keeps_its_mode(): void
    {
        $media = $this->embed();
        $run = Mediatheque::importEmbed($media, ImportMode::ReplaceOriginal);
        $this->assertNotNull($run);
        $job = $run->job('download');
        $this->assertNotNull($job);
        app(Recorder::class)->failed($job, new TranscoderError(TranscoderErrorCode::ProviderUnavailable, 'The provider is unavailable.'));

        $retry = Mediatheque::retry($run);

        $this->assertNotNull($retry);
        $this->completeDownload($retry);
        $media = $this->fresh($media);
        $this->assertFalse($media->isEmbed());
        $this->assertSame('video/mp4', $media->original()?->mime());
    }

    #[Test]
    public function a_stored_media_is_not_imported(): void
    {
        $stored = $this->createMedia(['type' => 'video']);
        $this->createFile($stored, 'original', ['mime' => 'video/mp4']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not an embed/');
        Mediatheque::importEmbed($stored);
    }

    #[Test]
    public function an_import_pipeline_needs_a_rendition_of_embed_download(): void
    {
        $embed = $this->embed();
        config(['mediatheque.pipelines.no_download' => ['transcoder' => 'import-test', 'renditions' => ['h264' => ['op' => 'video.transcode']]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no rendition of embed\.download/');
        Mediatheque::importEmbed($embed, pipeline: 'no_download');
    }

    #[Test]
    public function an_embed_is_not_imported_without_an_import_pipeline(): void
    {
        $embed = $this->embed();
        config(['mediatheque.embeds.import_pipeline' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No pipeline imports embeds/');
        Mediatheque::importEmbed($embed);
    }

    #[Test]
    public function the_fake_imports_too(): void
    {
        $fake = Mediatheque::fake();
        config(['mediatheque.pipelines.embed_import' => ['renditions' => ['download' => ['op' => 'embed.download']]]]);
        $media = Mediatheque::ingest(Source::embed('https://www.dailymotion.com/video/x7tgad0'));

        Mediatheque::importEmbed($media, ImportMode::ReplaceOriginal);

        $this->assertFalse($media->isEmbed());
        $this->assertSame('video/mp4', $media->original()?->mime());
        $this->assertSame('dailymotion', $media->metadata('provider'));
        // The embed's poster, the import, then the video's pipeline once probed again
        $this->assertSame(['embed', 'embed_import', 'video'], $fake->runs()->map(fn (PipelineRunContract $run) => $run->name())->all());
    }
}
