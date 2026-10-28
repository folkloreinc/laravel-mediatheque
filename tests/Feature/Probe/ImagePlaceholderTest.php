<?php

namespace Folklore\Mediatheque\Tests\Feature\Probe;

use Folklore\Mediatheque\Probe\ImagePlaceholder;
use Folklore\Mediatheque\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The placeholder of an image file, read with Imagick or GD. A ThumbHash's
 * header says whether the image is landscape and has alpha, which is what
 * these tests check: the rest is the encoder's (ThumbHashTest).
 */
class ImagePlaceholderTest extends TestCase
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function readers(): iterable
    {
        yield 'Imagick' => ['imagick'];
        yield 'GD' => ['gd'];
    }

    private function thumbhash(string $reader, string $path, ?int $orientation = null): ?string
    {
        if ($reader === 'imagick') {
            $this->requiresImagick();

            return ImagePlaceholder::withImagick($path);
        }

        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed.');
        }

        return ImagePlaceholder::withGd($path, $orientation);
    }

    /**
     * @return array{landscape: bool, alpha: bool}
     */
    private static function header(?string $thumbhash): array
    {
        $bytes = array_values(array_filter(unpack('C*', (string) base64_decode((string) $thumbhash, true)) ?: [], 'is_int'));

        return ['landscape' => (($bytes[4] ?? 0) & 0x80) !== 0, 'alpha' => (($bytes[2] ?? 0) & 0x80) !== 0];
    }

    #[Test]
    #[DataProvider('readers')]
    public function a_photo_gets_an_opaque_landscape_placeholder(string $reader): void
    {
        $thumbhash = $this->thumbhash($reader, self::fixturePath('media/image.jpg'));

        $this->assertIsString($thumbhash);
        $this->assertSame(['landscape' => true, 'alpha' => false], self::header($thumbhash));
    }

    #[Test]
    #[DataProvider('readers')]
    public function the_placeholder_is_upright(string $reader): void
    {
        // 80 by 60 pixels, to be shown turned a quarter clockwise (6)
        $thumbhash = $this->thumbhash($reader, self::fixturePath('media/image-orientation-6.jpg'), 6);

        $this->assertFalse(self::header($thumbhash)['landscape']);
    }

    #[Test]
    #[DataProvider('readers')]
    public function a_transparent_image_gets_a_placeholder_with_alpha(string $reader): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('GD is not installed.');
        }
        $image = imagecreatetruecolor(20, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 255, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, 9, 9, (int) imagecolorallocatealpha($image, 0, 0, 255, 0));
        $path = $this->file();
        imagepng($image, $path);

        $this->assertSame(['landscape' => true, 'alpha' => true], self::header($this->thumbhash($reader, $path)));
    }

    #[Test]
    #[DataProvider('readers')]
    public function a_file_that_is_not_an_image_has_no_placeholder(string $reader): void
    {
        $path = $this->file();
        file_put_contents($path, 'Just some text');

        $this->assertNull($this->thumbhash($reader, $path));
        $this->assertNull($this->thumbhash($reader, $path.'-missing'));
    }

    #[Test]
    public function the_heic_of_a_phone_gets_a_placeholder(): void
    {
        $this->requiresImagick();
        if (\Imagick::queryFormats('HEIC') === []) {
            $this->markTestSkipped('ImageMagick can\'t read HEIC.');
        }

        // 3 by 4 pixels, upright
        $this->assertFalse(self::header(ImagePlaceholder::withImagick(self::fixturePath('media/gps.heic')))['landscape']);
    }

    private function file(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'placeholder');
        $this->files[] = $path;

        return $path;
    }
}
