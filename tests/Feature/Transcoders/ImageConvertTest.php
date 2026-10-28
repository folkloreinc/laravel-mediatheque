<?php

namespace Folklore\Mediatheque\Tests\Feature\Transcoders;

use Closure;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\GpsImages;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Imagick;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `image` pipeline, with the sync queue: a HEIC gets a JPEG every
 * browser shows, played instead of its original (docs/decisions/0009).
 * Other images run nothing.
 */
class ImageConvertTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresImagick();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            LocalFiles::removeDirectory($directory);
        }

        parent::tearDown();
    }

    private function requiresHeic(): void
    {
        if (Imagick::queryFormats('HEIC') === []) {
            $this->markTestSkipped('ImageMagick can\'t read HEIC.');
        }
    }

    private function ingest(string $contents, string $name): Media
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        $media = Mediatheque::ingest(new UploadedFile($path, $name, null, null, true));
        $this->assertInstanceOf(Media::class, $media);

        return Media::query()->with('mediaFiles')->findOrFail($media->id);
    }

    /**
     * Run the operation on a local file, and read what it wrote.
     *
     * @param  array<string, mixed>  $options
     * @return array{Imagick, string}
     */
    private function convert(string $input, array $options = []): array
    {
        $directory = app(LocalFiles::class)->temporaryDirectory();
        $this->directories[] = $directory;

        $outputs = app(FfmpegTranscoder::class)->operation('image.convert')->run(new OperationContext(
            input: $input,
            outputDirectory: $directory,
            rendition: Rendition::fromArray('web', ['op' => 'image.convert', ...$options]),
            metadata: [],
            progress: Closure::fromCallable(fn (float $progress) => null),
            timeout: 60,
        ));
        $this->assertCount(1, $outputs);

        return [new Imagick($outputs[0]->path), $outputs[0]->mime];
    }

    #[Test]
    public function a_heic_gets_a_jpeg_that_its_media_plays(): void
    {
        $this->requiresHeic();
        Event::fake([MediaReady::class]);

        $media = $this->ingest((string) file_get_contents(self::fixturePath('media/gps.heic')), 'IMG_0001.HEIC');

        $this->assertSame([MediaStatus::Ready, 'image/heic'], [$media->status(), $media->original()?->mime()]);
        $web = $media->file('web');
        $this->assertNotNull($web);
        $this->assertSame(['image/jpeg', 3, 4], [$web->mime(), $web->width(), $web->height()], 'Upright, of the same size.');
        $this->assertIsString($web->metadata('thumbhash'), 'The JPEG has its own placeholder.');
        $this->assertSame($web->url(), $media->url(), 'The media plays its JPEG.');
        $this->assertNotSame($web->url(), $media->original()?->url(), 'The HEIC is kept.');
        Storage::disk('public')->assertExists((string) $media->original()?->path());
        Event::assertDispatchedTimes(MediaReady::class, 1);

        $jpeg = (string) Storage::disk('public')->get((string) $web->path());
        $this->assertStringNotContainsString(GpsImages::DATUM, $jpeg);
        $this->assertStringNotContainsString('Exif', $jpeg, 'No metadata but the color profile is copied.');
    }

    #[Test]
    public function the_heic_is_not_ready_before_its_jpeg(): void
    {
        $this->requiresHeic();
        // The probe runs, the rendition waits in the queue
        Bus::fake([RunRendition::class]);

        $media = $this->ingest((string) file_get_contents(self::fixturePath('media/gps.heic')), 'IMG_0001.HEIC');

        $this->assertSame(MediaStatus::Processing, $media->status());
        Bus::assertDispatchedTimes(RunRendition::class, 1);
        $this->assertSame($media->original()?->url(), $media->url(), 'Until then, the original.');
    }

    #[Test]
    public function other_images_run_nothing_and_are_ready_once_probed(): void
    {
        Event::fake([MediaReady::class]);

        $media = $this->ingest((string) file_get_contents(self::fixturePath('media/image.jpg')), 'photo.jpg');

        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertNull($media->file('web'));
        $this->assertSame($media->original()?->url(), $media->url());
        $this->assertSame(['probe'], PipelineRun::query()->pluck('name')->all(), 'The image pipeline doesn\'t run.');
        Event::assertDispatchedTimes(MediaReady::class, 1);
    }

    #[Test]
    public function it_turns_the_image_upright_and_drops_its_metadata(): void
    {
        $input = (string) tempnam(sys_get_temp_dir(), 'convert');
        file_put_contents($input, GpsImages::jpeg());

        try {
            [$image, $mime] = $this->convert($input);
        } finally {
            @unlink($input);
        }

        // 4 by 3 pixels, to be shown turned a quarter clockwise (6)
        $this->assertSame(['image/jpeg', 3, 4], [$mime, $image->getImageWidth(), $image->getImageHeight()]);
        $this->assertSame([], $image->getImageProperties('exif:*'));
        $this->assertNotContains($image->getImageOrientation(), [2, 3, 4, 5, 6, 7, 8]);
    }

    #[Test]
    public function a_transparent_image_becomes_a_jpeg_over_white_or_keeps_its_alpha_in_png(): void
    {
        $input = (string) tempnam(sys_get_temp_dir(), 'convert');
        $transparent = new Imagick;
        $transparent->newImage(4, 4, 'transparent', 'png');
        $transparent->writeImage('png:'.$input);

        try {
            [$jpeg, $jpegMime] = $this->convert($input);
            [$png, $pngMime] = $this->convert($input, ['format' => 'png']);
        } finally {
            @unlink($input);
        }

        $this->assertSame('image/jpeg', $jpegMime);
        $color = $jpeg->getImagePixelColor(1, 1)->getColor();
        $this->assertSame([255, 255, 255], [$color['r'], $color['g'], $color['b']]);
        $this->assertSame('image/png', $pngMime);
        $this->assertTrue($png->getImageAlphaChannel());
    }

    #[Test]
    public function an_unknown_format_fails_the_rendition(): void
    {
        try {
            $this->convert(self::fixturePath('media/image.jpg'), ['format' => 'tiff']);
            $this->fail('The operation did not fail.');
        } catch (OperationFailed $exception) {
            $this->assertSame(TranscoderErrorCode::OperationFailed, $exception->error->code);
        }
    }

    #[Test]
    public function an_image_imagemagick_cannot_read_fails_as_unsupported(): void
    {
        $input = (string) tempnam(sys_get_temp_dir(), 'convert');
        file_put_contents($input, 'Just some text');

        try {
            $this->convert($input);
            $this->fail('The operation did not fail.');
        } catch (OperationFailed $exception) {
            $this->assertSame(TranscoderErrorCode::UnsupportedInput, $exception->error->code);
        } finally {
            @unlink($input);
        }
    }
}
