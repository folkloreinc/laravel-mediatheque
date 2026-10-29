<?php

namespace Folklore\Mediatheque\Tests\Feature\Transcoders;

use Closure;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `document` pipeline, with the sync queue: an image of every page of
 * a PDF, rendered by Poppler's pdftoppm, as 1.x made (#147,
 * docs/decisions/0025). The fixture has two pages: a portrait letter page,
 * then a landscape one.
 */
class DocumentThumbnailsTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            LocalFiles::removeDirectory($directory);
        }

        parent::tearDown();
    }

    private function ingest(string $fixture, string $name): Media
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        copy(self::fixturePath($fixture), $path);

        $media = Mediatheque::ingest(new UploadedFile($path, $name, null, null, true));
        $this->assertInstanceOf(Media::class, $media);

        return Media::query()->with('mediaFiles')->findOrFail($media->id);
    }

    /**
     * Run the operation on a local file.
     *
     * @param  array<string, mixed>  $options
     * @return list<LocalOutput>
     */
    private function render(string $input, array $options = []): array
    {
        $directory = app(LocalFiles::class)->temporaryDirectory();
        $this->directories[] = $directory;
        $progress = [];

        $outputs = app(FfmpegTranscoder::class)->operation('document.thumbnails')->run(new OperationContext(
            input: $input,
            outputDirectory: $directory,
            rendition: Rendition::fromArray('thumbnails', ['op' => 'document.thumbnails', 'role' => 'thumbnail', ...$options]),
            metadata: [],
            progress: Closure::fromCallable(function (float $value) use (&$progress) {
                $progress[] = $value;
            }),
            timeout: 60,
        ));

        foreach ($progress as $value) {
            $this->assertTrue($value > 0 && $value < 1);
        }

        return $outputs;
    }

    /**
     * @return array{int, int}
     */
    private static function dimensions(string $path): array
    {
        $size = getimagesize($path);

        return $size !== false ? [$size[0], $size[1]] : [0, 0];
    }

    #[Test]
    public function a_document_gets_an_image_of_every_page(): void
    {
        $this->requiresBinary('pdftoppm');

        $media = $this->ingest('media/document.pdf', 'media-kit.pdf');

        $this->assertSame(['document', MediaStatus::Ready], [$media->type(), $media->status()]);
        $run = PipelineRun::query()->where('name', 'document')->firstOrFail();
        $this->assertSame(PipelineStatus::Completed, $run->status());

        $first = $media->file('thumbnails', 0);
        $second = $media->file('thumbnails', 1);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($media->file('thumbnails', 2));
        $this->assertSame(['image/jpeg', 'thumbnail'], [$first->mime(), $first->role()]);
        $this->assertSame($first->url(), $media->thumbnail()?->url(), 'The first page is the media\'s thumbnail.');

        // A letter page at 150 dpi, upright, then the landscape page
        $disk = Storage::disk('public');
        $this->assertSame([1275, 1650], self::dimensions($disk->path((string) $first->path())));
        $this->assertSame([1650, 1275], self::dimensions($disk->path((string) $second->path())));
    }

    #[Test]
    public function the_document_is_ready_before_its_thumbnails(): void
    {
        // The probe runs, the thumbnails wait in the queue
        Bus::fake([RunRendition::class]);

        $media = $this->ingest('media/document.pdf', 'media-kit.pdf');

        $this->assertSame(MediaStatus::Ready, $media->status());
        Bus::assertDispatchedTimes(RunRendition::class, 1);
    }

    #[Test]
    public function it_renders_the_first_pages_in_png_at_a_resolution(): void
    {
        $this->requiresBinary('pdftoppm');

        $outputs = $this->render(self::fixturePath('media/document.pdf'), ['count' => 1, 'format' => 'png', 'resolution' => 72]);

        $this->assertCount(1, $outputs);
        $this->assertSame(['image/png', 0], [$outputs[0]->mime, $outputs[0]->index]);
        $this->assertSame([612, 792], self::dimensions($outputs[0]->path));
    }

    #[Test]
    public function a_count_past_the_last_page_renders_every_page(): void
    {
        $this->requiresBinary('pdftoppm');

        $outputs = $this->render(self::fixturePath('media/document.pdf'), ['count' => 5, 'resolution' => 36, 'quality' => 80]);

        $this->assertSame([0, 1], array_map(fn (LocalOutput $output) => $output->index, $outputs));
        $this->assertSame([[306, 396], [396, 306]], array_map(fn (LocalOutput $output) => self::dimensions($output->path), $outputs));
    }

    #[Test]
    public function an_unknown_format_fails_the_rendition(): void
    {
        try {
            $this->render(self::fixturePath('media/document.pdf'), ['format' => 'tiff']);
            $this->fail('The operation did not fail.');
        } catch (OperationFailed $exception) {
            $this->assertSame(TranscoderErrorCode::OperationFailed, $exception->error->code);
        }
    }

    #[Test]
    public function a_file_poppler_cannot_read_fails_as_unreadable(): void
    {
        $this->requiresBinary('pdftoppm');

        try {
            $this->render(self::fixturePath('media/image.svg'));
            $this->fail('The operation did not fail.');
        } catch (OperationFailed $exception) {
            $this->assertSame(TranscoderErrorCode::InputUnreadable, $exception->error->code);
        }
    }

    #[Test]
    public function without_pdftoppm_the_thumbnails_fail_and_the_document_is_ready(): void
    {
        config(['mediatheque.binaries.pdftoppm' => '/nonexistent/pdftoppm']);

        $media = $this->ingest('media/document.pdf', 'media-kit.pdf');

        $this->assertSame(MediaStatus::Ready, $media->status());
        $run = PipelineRun::query()->where('name', 'document')->firstOrFail();
        $this->assertSame(PipelineStatus::Failed, $run->status());
        $this->assertStringContainsString('mediatheque.binaries.pdftoppm', (string) $run->job('thumbnails')?->error()?->message);
        $this->assertNull($media->file('thumbnails', 0));
    }
}
