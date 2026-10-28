<?php

namespace Folklore\Mediatheque\Tests\Feature\Probe;

use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Probe\LocalProber;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class LocalProberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function probe(string $fixture, string $mime, string $disk = 'public', ?string $contents = null): ProbeOutcome
    {
        $path = 'files/'.basename($fixture);
        Storage::disk($disk)->put($path, $contents ?? (string) file_get_contents(self::fixturePath($fixture)));

        $file = (new File)->forceFill(['handle' => 'original', 'source' => $disk, 'path' => $path, 'mime' => $mime]);

        return app(Prober::class)->probe($file);
    }

    #[Test]
    public function the_package_binds_the_local_prober(): void
    {
        $this->assertInstanceOf(LocalProber::class, app(Prober::class));
    }

    #[Test]
    public function it_probes_a_video(): void
    {
        $this->requiresBinary('ffprobe');

        $outcome = $this->probe('media/test.mp4', 'video/mp4');

        $this->assertTrue($outcome->isDone());
        $this->assertSame([
            'kind' => 'video',
            'mime' => 'video/mp4',
            'duration' => 1.0,
            'bitrate' => 397480,
            'width' => 320,
            'height' => 180,
            'rotation' => 0,
            'frame_rate' => 30.0,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'audio_channels' => 2,
        ], $outcome->metadata);
    }

    #[Test]
    public function the_size_of_a_vertical_video_is_its_displayed_size(): void
    {
        $this->requiresBinary('ffprobe');

        $metadata = $this->probe('media/test_vertical_hevc.mp4', 'video/mp4')->metadata;

        $this->assertSame('hevc', $metadata['video_codec']);
        $this->assertSame([1080, 1920], [$metadata['width'], $metadata['height']]);
        $this->assertContains($metadata['rotation'], [0, 90, 180, 270]);
    }

    #[Test]
    public function it_probes_an_audio_file(): void
    {
        $this->requiresBinary('ffprobe');

        $metadata = $this->probe('media/test.wav', 'audio/x-wav')->metadata;

        $this->assertSame('audio', $metadata['kind']);
        $this->assertSame(0.84, $metadata['duration']);
        $this->assertArrayNotHasKey('width', $metadata);
        $this->assertSame('pcm_f32le', $metadata['audio_codec']);
    }

    #[Test]
    public function a_file_ffprobe_cannot_read_fails_as_unreadable(): void
    {
        $this->requiresBinary('ffprobe');

        $outcome = $this->probe('broken.mp4', 'video/mp4', contents: 'Not a video');

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(TranscoderErrorCode::InputUnreadable, $outcome->error?->code);
    }

    #[Test]
    public function a_missing_binary_throws_so_the_job_is_retried(): void
    {
        config(['mediatheque.binaries.ffprobe' => '/missing/ffprobe']);

        $this->expectException(\RuntimeException::class);

        $this->probe('media/test.mp4', 'video/mp4');
    }

    #[Test]
    public function it_probes_images_with_imagick(): void
    {
        $this->requiresImagick();

        $jpeg = $this->probe('media/image.jpg', 'image/jpeg')->metadata;
        $gif = $this->probe('media/animated.gif', 'image/gif')->metadata;

        $this->assertIsString($jpeg['thumbhash'] ?? null);
        unset($jpeg['thumbhash']);
        $this->assertSame(['kind' => 'image', 'mime' => 'image/jpeg', 'width' => 800, 'height' => 600, 'orientation' => 1, 'animated' => false], $jpeg);
        $this->assertIsString($gif['thumbhash'] ?? null, 'An animated image gets the placeholder of its first frame.');
        $this->assertSame([400, 400, true], [$gif['width'], $gif['height'], $gif['animated']]);
        $this->assertArrayNotHasKey('orientation', $gif, 'A GIF declares no orientation.');
    }

    #[Test]
    public function an_image_turned_by_its_exif_orientation_has_its_upright_size_and_its_orientation(): void
    {
        $this->requiresImagick();

        // 80 by 60 pixels, to be shown turned a quarter clockwise (6)
        $metadata = $this->probe('media/image-orientation-6.jpg', 'image/jpeg')->metadata;

        $this->assertSame([60, 80, 6], [$metadata['width'], $metadata['height'], $metadata['orientation']]);
    }

    #[Test]
    public function it_reads_the_size_an_svg_declares(): void
    {
        $metadata = $this->probe('media/image.svg', 'image/svg+xml')->metadata;

        $this->assertSame('image', $metadata['kind']);
        $this->assertArrayHasKey('width', $metadata);
        $this->assertArrayNotHasKey('thumbhash', $metadata, 'An SVG is not rasterized.');
    }

    #[Test]
    public function an_image_whose_placeholder_cannot_be_computed_is_still_probed(): void
    {
        $this->requiresImagick();

        // A PNG whose header is valid, and whose pixels are not
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 20, 10, 8, 6, 0, 0, 0)).$chunk('IDAT', 'not zlib data').$chunk('IEND', '');
        $outcome = $this->probe('media/broken.png', 'image/png', contents: $png);

        $this->assertTrue($outcome->isDone());
        $this->assertSame([20, 10], [$outcome->metadata['width'] ?? null, $outcome->metadata['height'] ?? null]);
        $this->assertArrayNotHasKey('thumbhash', $outcome->metadata);
    }

    #[Test]
    public function documents_and_fonts_report_their_kind(): void
    {
        $this->assertSame(['kind' => 'font', 'mime' => 'font/otf'], $this->probe('media/font.otf', 'font/otf')->metadata);
        $this->assertSame('document', LocalProber::kindOf('application/pdf')->value);
        $this->assertSame('other', LocalProber::kindOf('text/vtt')->value);
    }

    #[Test]
    public function it_probes_files_of_remote_disks_and_leaves_no_temporary_file(): void
    {
        $this->requiresImagick();
        $before = glob(LocalFiles::root().'/*') ?: [];

        // A disk that is not local, without temporary URLs: the file is copied locally.
        $this->useMemoryDisk('memory');

        $metadata = $this->probe('media/image.jpg', 'image/jpeg', 'memory')->metadata;

        $this->assertSame(800, $metadata['width']);
        $this->assertSame($before, glob(LocalFiles::root().'/*') ?: []);
    }
}
