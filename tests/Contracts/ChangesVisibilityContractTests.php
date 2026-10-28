<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\MediaVisibilityChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mediatheque::setVisibility() (docs/decisions/0007): the media's stored
 * files follow its visibility, including those a run records later.
 */
trait ChangesVisibilityContractTests
{
    abstract protected function mediatheque(): Mediatheque;

    /**
     * A public video whose original and `h264` rendition are stored on a
     * disk, as public files.
     */
    abstract protected function videoWithStoredFiles(): Media;

    abstract protected function freshMedia(Media $media): Media;

    /**
     * Renditions of the runs created from now on stay queued, as if no
     * worker ran them.
     */
    abstract protected function holdRuns(): void;

    /**
     * Complete the renditions of a held run, whose driver writes their files
     * with the run's visibility.
     */
    abstract protected function completeHeldRun(PipelineRun $run): void;

    /**
     * The visibility of a file on its disk.
     */
    protected function storedVisibility(File $file): ?string
    {
        $source = $file->source();
        $path = $file->path();
        $this->assertNotNull($source);
        $this->assertNotNull($path);

        return Storage::disk($source)->getVisibility($path);
    }

    /**
     * @return list<string|null>
     */
    protected function storedVisibilities(Media $media): array
    {
        return array_values($this->freshMedia($media)->files()->map(fn (File $file) => $this->storedVisibility($file))->all());
    }

    #[Test]
    public function a_media_made_private_gets_private_files(): void
    {
        $media = $this->videoWithStoredFiles();
        $events = [];
        Event::listen(MediaVisibilityChanged::class, function (MediaVisibilityChanged $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertTrue($this->mediatheque()->setVisibility($media, Visibility::Private));

        $this->assertSame(Visibility::Private, $media->visibility(), 'The media has its visibility at once.');
        $this->assertSame(Visibility::Private, $media->original()?->visibility());
        $this->assertSame(Visibility::Private, $this->freshMedia($media)->visibility());
        $this->assertGreaterThanOrEqual(2, $this->freshMedia($media)->files()->count());
        $this->assertSame(['private'], array_values(array_unique($this->storedVisibilities($media))));
        $this->assertCount(1, $events);
        $this->assertSame($media->uid(), $events[0]->media->uid());
        $this->assertSame([Visibility::Public, Visibility::Private], [$events[0]->previous, $events[0]->visibility]);
    }

    #[Test]
    public function a_media_made_public_again_gets_public_files(): void
    {
        $media = $this->videoWithStoredFiles();
        $this->mediatheque()->setVisibility($media, Visibility::Private);

        $this->assertTrue($this->mediatheque()->setVisibility($media, Visibility::Public));

        $this->assertSame(Visibility::Public, $this->freshMedia($media)->visibility());
        $this->assertSame(['public'], array_values(array_unique($this->storedVisibilities($media))));
    }

    #[Test]
    public function the_same_visibility_again_changes_nothing(): void
    {
        $media = $this->videoWithStoredFiles();
        $this->mediatheque()->setVisibility($media, Visibility::Private);
        $dispatched = 0;
        Event::listen(MediaVisibilityChanged::class, function () use (&$dispatched) {
            $dispatched++;
        });

        $this->assertFalse($this->mediatheque()->setVisibility($media, Visibility::Private));

        $this->assertSame(0, $dispatched);
        $this->assertSame(Visibility::Private, $this->freshMedia($media)->visibility());
        $this->assertSame(['private'], array_values(array_unique($this->storedVisibilities($media))));
    }

    #[Test]
    public function the_files_of_a_run_in_progress_get_the_visibility_when_they_are_recorded(): void
    {
        $media = $this->videoWithStoredFiles();
        $this->holdRuns();
        $run = $this->mediatheque()->runPipeline($media, 'video', force: true);
        $this->assertNotNull($run);
        $this->assertSame(Visibility::Public, $run->visibility());

        $this->mediatheque()->setVisibility($media, Visibility::Private);
        $this->completeHeldRun($run);

        $media = $this->freshMedia($media);
        $this->assertNotNull($media->file('h264'));
        $this->assertSame(['private'], array_values(array_unique($this->storedVisibilities($media))));
    }
}
