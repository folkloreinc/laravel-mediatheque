<?php

namespace Folklore\Mediatheque\Tests\Unit\Ingest;

use Folklore\Mediatheque\Ingest\GpsStripper;
use Folklore\Mediatheque\Tests\Support\GpsImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The location removed from each format, in place: the file keeps its
 * length, its other metadata and its pixels.
 */
class GpsStripperTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gps');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function strip(string $path, string $mime): array
    {
        $patches = (new GpsStripper)->patches($path, $mime);
        GpsStripper::apply($path, $patches);

        return $patches;
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function images(): iterable
    {
        yield 'JPEG' => ['image/jpeg', 'jpeg', false];
        yield 'PNG' => ['image/png', 'png', true];
        yield 'WebP' => ['image/webp', 'webp', false];
        yield 'TIFF' => ['image/tiff', 'tiff', false];
        yield 'HEIC' => ['image/heic', 'heic', false];
    }

    private function image(string $format): string
    {
        if ($format === 'heic') {
            // heif-enc's encoding of GpsImages::jpeg(), which keeps its EXIF and XMP
            return (string) file_get_contents(__DIR__.'/../../fixtures/media/gps.heic');
        }

        if (! function_exists($format === 'webp' ? 'imagewebp' : 'imagecreatetruecolor')) {
            $this->markTestSkipped('GD, or its WebP support, is not installed.');
        }

        return match ($format) {
            'jpeg' => GpsImages::jpeg(),
            'png' => GpsImages::png(),
            'webp' => GpsImages::webp(),
            default => GpsImages::tiff(),
        };
    }

    #[Test]
    #[DataProvider('images')]
    public function it_removes_the_location_and_keeps_the_rest(string $mime, string $format, bool $littleEndian): void
    {
        $before = $this->image($format);
        $path = $this->file($before);
        $this->assertStringContainsString(GpsImages::DATUM, $before);
        $this->assertStringContainsString(GpsImages::latitude($littleEndian), $before);

        $this->assertNotSame([], $this->strip($path, $mime));

        $after = (string) file_get_contents($path);
        $this->assertSame(strlen($before), strlen($after), 'The file keeps its length.');
        $this->assertStringNotContainsString(GpsImages::DATUM, $after);
        $this->assertStringNotContainsString(GpsImages::latitude($littleEndian), $after);
        $this->assertStringNotContainsString(GpsImages::latitude(! $littleEndian), $after);
        $this->assertStringNotContainsString(GpsImages::XMP_LONGITUDE, $after);
        $this->assertStringNotContainsString(GpsImages::XMP_LATITUDE, $after);
        $this->assertStringNotContainsString('GPSLatitude', $after);
        $this->assertStringContainsString(GpsImages::MAKE, $after, 'The camera stays, in EXIF and XMP.');
        $this->assertSame([], (new GpsStripper)->patches($path, $mime), 'Stripping again changes nothing.');
    }

    /**
     * The formats GD decodes.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function decodedImages(): iterable
    {
        yield 'JPEG' => ['image/jpeg', 'jpeg'];
        yield 'PNG' => ['image/png', 'png'];
        yield 'WebP' => ['image/webp', 'webp'];
    }

    #[Test]
    #[DataProvider('decodedImages')]
    public function the_pixels_do_not_change(string $mime, string $format): void
    {
        $before = $this->image($format);
        $path = $this->file($before);
        $this->strip($path, $mime);

        $this->assertSame($this->pixels($before), $this->pixels((string) file_get_contents($path)));
    }

    #[Test]
    public function a_jpeg_keeps_its_orientation_and_camera(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('The exif extension is not installed.');
        }

        $path = $this->file($this->image('jpeg'));
        $exif = exif_read_data($path, null, true);
        $this->assertIsArray($exif);
        $this->assertArrayHasKey('GPS', $exif);

        $this->strip($path, 'image/jpeg');

        $exif = exif_read_data($path, null, true);
        $this->assertIsArray($exif);
        $this->assertArrayNotHasKey('GPS', $exif);
        $this->assertIsArray($exif['IFD0'] ?? null);
        $this->assertSame(6, $exif['IFD0']['Orientation'] ?? null);
        $this->assertSame(GpsImages::MAKE, $exif['IFD0']['Make'] ?? null);
    }

    #[Test]
    public function the_crc_of_a_png_chunk_is_written_again(): void
    {
        $path = $this->file($this->image('png'));
        $this->strip($path, 'image/png');
        $png = (string) file_get_contents($path);

        for ($position = 8; $position + 12 <= strlen($png);) {
            $length = self::u32(substr($png, $position, 4));
            $type = substr($png, $position + 4, 4);
            $crc = self::u32(substr($png, $position + 8 + $length, 4));
            $this->assertSame(crc32($type.substr($png, $position + 8, $length)), $crc, "The CRC of {$type}");
            $position += 12 + $length;
        }

        $this->assertNotFalse(imagecreatefromstring($png));
    }

    #[Test]
    public function an_image_without_a_location_needs_no_patch(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $path = $this->file(GpsImages::jpegWithoutExif());

        $this->assertSame([], (new GpsStripper)->patches($path, 'image/jpeg'));
    }

    #[Test]
    public function a_truncated_or_unknown_file_needs_no_patch(): void
    {
        $jpeg = $this->file(substr($this->image('jpeg'), 0, 40));
        $text = $this->file('Just some text');

        $this->assertSame([], (new GpsStripper)->patches($jpeg, 'image/jpeg'));
        $this->assertSame([], (new GpsStripper)->patches($text, 'image/heic'));
        $this->assertSame([], (new GpsStripper)->patches($text, 'image/gif'));
        $this->assertFalse(GpsStripper::supports('image/gif'));
        $this->assertFalse(GpsStripper::supports('image/svg+xml'));
    }

    private static function u32(string $bytes): int
    {
        $value = unpack('N', $bytes);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : -1;
    }

    /**
     * The colours of every pixel, decoded by GD.
     */
    private function pixels(string $image): string
    {
        $decoded = imagecreatefromstring($image);
        $this->assertNotFalse($decoded);
        $colors = '';
        for ($y = 0; $y < imagesy($decoded); $y++) {
            for ($x = 0; $x < imagesx($decoded); $x++) {
                $colors .= imagecolorat($decoded, $x, $y).',';
            }
        }

        return $colors;
    }
}
