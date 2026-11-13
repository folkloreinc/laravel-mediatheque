<?php

namespace Folklore\Mediatheque\Tests\Feature\Embeds;

use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\EmbedRefreshed;
use Folklore\Mediatheque\Events\EmbedUnavailable;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Http\Resources\MediaResource;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

/**
 * `mediatheque:refresh-embeds` (docs/decisions/0031): ready embeds are
 * probed again, as a run of the `probe` pipeline marked `refresh`, and
 * follow their provider without changing their status. The queue is sync;
 * the poster's renditions are faked.
 */
class RefreshEmbedsTest extends DatabaseTestCase
{
    use CreatesMedia;

    private const string OEMBED = 'vimeo.com/api/oembed.json*';

    /** What Vimeo answers now. */
    private mixed $response = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([RunRendition::class]);
        Http::preventStrayRequests();
        Http::fake([self::OEMBED => fn () => $this->response]);
    }

    /**
     * Vimeo's answer, with some keys replaced.
     *
     * @param  array<string, mixed>  $changes
     * @return array<mixed>
     */
    private static function answer(array $changes = []): array
    {
        $json = json_decode((string) file_get_contents(self::fixturePath('embeds/vimeo-oembed.json')), true);

        return [...(is_array($json) ? $json : []), ...$changes];
    }

    /**
     * A ready embed, probed eight days ago.
     */
    private function embed(): Media
    {
        $this->travelTo('2026-11-01 10:00:00');
        $this->answers(Http::response(self::answer()));
        $ingested = Mediatheque::ingest(Source::embed('https://vimeo.com/76979871'));
        $this->travelTo('2026-11-09 10:00:00');

        $media = $this->fresh($ingested->uid());
        $this->assertSame(MediaStatus::Ready, $media->status());

        return $media;
    }

    private function fresh(string $uid): Media
    {
        return Media::query()->where('uid', $uid)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function refresh(array $options = []): PendingCommand
    {
        $command = $this->artisan('mediatheque:refresh-embeds', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function answers(mixed $response): void
    {
        $this->response = $response;
    }

    #[Test]
    public function a_refresh_follows_the_provider_and_makes_a_new_poster_when_the_thumbnail_changed(): void
    {
        Event::fake([EmbedRefreshed::class, MediaFailed::class]);
        $media = $this->embed();
        $this->createFile($media, 'poster', ['role' => 'poster', 'mime' => 'image/jpeg']);
        $this->answers(Http::response(self::answer([
            'title' => 'A new title',
            'thumbnail_url' => 'https://i.vimeocdn.com/video/999-new-d_1280?region=us',
        ])));

        $this->refresh()->assertSuccessful();

        $media = $this->fresh($media->uid());
        $this->assertSame([MediaStatus::Ready, 'A new title'], [$media->status(), $media->metadata('title')]);
        $this->assertSame('https://i.vimeocdn.com/video/999-new-d_1280?region=us', $media->metadata('thumbnail_url'));
        Event::assertDispatched(EmbedRefreshed::class, fn (EmbedRefreshed $event) => $event->media->uid() === $media->uid() && $event->metadata['title'] === 'A new title');
        $refresh = PipelineRun::query()->where('name', 'probe')->latest('id')->firstOrFail();
        $this->assertTrue($refresh->isRefresh());
        // The poster again, forced, from the new thumbnail
        $this->assertSame(2, PipelineRun::query()->where('name', 'embed')->count());
        Event::assertNotDispatched(MediaFailed::class);
    }

    #[Test]
    public function the_same_thumbnail_signed_again_makes_no_new_poster(): void
    {
        $media = $this->embed();
        $this->createFile($media, 'poster', ['role' => 'poster', 'mime' => 'image/jpeg']);
        $thumbnail = self::answer()['thumbnail_url'];
        $this->assertIsString($thumbnail);
        $this->answers(Http::response(self::answer(['thumbnail_url' => explode('?', $thumbnail)[0].'?region=eu&expires=2'])));

        $this->refresh()->assertSuccessful();

        $this->assertSame(1, PipelineRun::query()->where('name', 'embed')->count());
    }

    #[Test]
    public function an_embed_gone_from_its_provider_is_marked_unavailable_and_stays_ready(): void
    {
        Event::fake([EmbedUnavailable::class, MediaFailed::class]);
        $media = $this->embed();
        $this->answers(Http::response('Not Found', 404));

        $this->refresh()->assertSuccessful();

        $media = $this->fresh($media->uid());
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame('2026-11-09T10:00:00+00:00', $media->metadata('unavailable_at'));
        $this->assertSame('The New Vimeo Player (You Know, For Videos)', $media->metadata('title'), 'What the provider said stays.');
        Event::assertDispatched(EmbedUnavailable::class, fn (EmbedUnavailable $event) => $event->error->code === TranscoderErrorCode::ProviderUnavailable);
        Event::assertNotDispatched(MediaFailed::class);
        $json = (new MediaResource($media))->toArray(new Request);
        $this->assertIsArray($json['embed']);
        $this->assertFalse($json['embed']['available']);

        // Back at its provider: available again
        $this->travel(8)->days();
        $this->answers(Http::response(self::answer()));
        $this->refresh()->assertSuccessful();

        $this->assertNull($this->fresh($media->uid())->metadata('unavailable_at'));
        Event::assertDispatchedTimes(EmbedUnavailable::class, 1);
    }

    #[Test]
    public function another_failure_changes_nothing_until_the_next_refresh(): void
    {
        Event::fake([EmbedRefreshed::class, EmbedUnavailable::class]);
        $media = $this->embed();
        $this->answers(Http::response('Too Many Requests', 429));

        $this->refresh()->assertSuccessful();

        $fresh = $this->fresh($media->uid());
        $this->assertSame([MediaStatus::Ready, null], [$fresh->status(), $fresh->metadata('unavailable_at')]);
        $this->assertSame($media->metadata(), $fresh->metadata());
        Event::assertNotDispatched(EmbedRefreshed::class);
        Event::assertNotDispatched(EmbedUnavailable::class);
        $this->assertSame(TranscoderErrorCode::ProviderRefused->value, PipelineRun::query()->where('name', 'probe')->latest('id')->firstOrFail()->job('probe')?->error()?->code->value);
    }

    #[Test]
    public function only_ready_embeds_not_probed_for_some_days_are_refreshed(): void
    {
        $embed = $this->embed();
        $recent = $this->embed();
        $this->travel(-8)->days();
        $this->travel(5)->days();
        app()->call([new ProbeMedia($recent->id, refresh: true), 'handle']);
        $this->travel(3)->days();
        $stored = $this->createMedia(['type' => 'video', 'status' => MediaStatus::Ready]);
        $this->createFile($stored, 'original', ['mime' => 'video/mp4']);
        Bus::fake([ProbeMedia::class, RunRendition::class]);

        $this->refresh(['--days' => 7])
            ->expectsOutputToContain('1')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(ProbeMedia::class, 1);
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $embed->id && $job->refresh);

        $this->refresh(['--media' => [$recent->uid(), $stored->uid()]])->assertSuccessful();

        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $recent->id && $job->refresh);
        Bus::assertNotDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $stored->id);
    }

    #[Test]
    public function a_refresh_job_leaves_other_media_alone(): void
    {
        $stored = $this->createMedia(['type' => 'video', 'status' => MediaStatus::Ready]);
        $this->createFile($stored, 'original', ['mime' => 'video/mp4']);

        app()->call([new ProbeMedia($stored->id, refresh: true), 'handle']);

        $this->assertSame(0, PipelineRun::query()->count());
    }
}
