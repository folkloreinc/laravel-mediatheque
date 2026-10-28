<?php

namespace Folklore\Mediatheque\Probe;

use GdImage;
use Imagick;
use Throwable;

/**
 * The ThumbHash placeholder of an image file (docs/decisions/0013): its
 * first frame, upright, downsized to fit 100×100, read with Imagick when it
 * is installed, else GD. Null when neither can read it: a placeholder never
 * fails a probe.
 */
final class ImagePlaceholder
{
    public static function thumbhash(string $path, ?int $orientation = null): ?string
    {
        return class_exists(Imagick::class) ? self::withImagick($path) : self::withGd($path, $orientation);
    }

    /**
     * Read with Imagick, which turns the image upright itself.
     */
    public static function withImagick(string $path): ?string
    {
        try {
            $pixels = self::imagick($path);
        } catch (Throwable) {
            return null;
        }

        return ThumbHash::encode(...$pixels);
    }

    /**
     * Read with GD, which ignores the EXIF orientation: the prober gives it.
     */
    public static function withGd(string $path, ?int $orientation = null): ?string
    {
        try {
            $pixels = self::gd($path, $orientation);
        } catch (Throwable) {
            return null;
        }

        return $pixels !== null ? ThumbHash::encode(...$pixels) : null;
    }

    /**
     * The width, height and RGBA bytes of the image, upright and downsized.
     *
     * @return array{int, int, list<int>}
     */
    private static function imagick(string $path): array
    {
        $image = new Imagick;
        // Lets the JPEG decoder skip most of a large image
        $image->setOption('jpeg:size', '200x200');
        $image->readImage($path);
        $image->setFirstIterator();
        $frame = $image->getImage();
        $image->clear();

        $frame->autoOrient();
        [$width, $height] = self::fit($frame->getImageWidth(), $frame->getImageHeight());
        $frame->thumbnailImage($width, $height);
        $rgba = $frame->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);
        $frame->clear();

        return [$width, $height, $rgba];
    }

    /**
     * @return array{int, int, list<int>}|null
     */
    private static function gd(string $path, ?int $orientation): ?array
    {
        $contents = @file_get_contents($path);
        $image = is_string($contents) && $contents !== '' ? @imagecreatefromstring($contents) : false;
        if ($image === false) {
            return null;
        }

        [$width, $height] = self::fit(imagesx($image), imagesy($image));
        $scaled = imagecreatetruecolor($width, $height);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
        $scaled = self::orient($scaled, $orientation ?? 1);
        $width = imagesx($scaled);
        $height = imagesy($scaled);

        $rgba = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($scaled, $x, $y);
                $rgba[] = ($color >> 16) & 0xFF;
                $rgba[] = ($color >> 8) & 0xFF;
                $rgba[] = $color & 0xFF;
                // GD's alpha goes from 0, opaque, to 127, transparent
                $rgba[] = (int) round((127 - (($color >> 24) & 0x7F)) * 255 / 127);
            }
        }

        return [$width, $height, $rgba];
    }

    /**
     * Turn a GD image upright, from its EXIF orientation.
     */
    private static function orient(GdImage $image, int $orientation): GdImage
    {
        // imagerotate() turns counterclockwise
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        $image = $angle !== 0 ? (imagerotate($image, $angle, 0) ?: $image) : $image;

        if ($orientation === 2 || $orientation === 4 || $orientation === 5 || $orientation === 7) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * A size that fits in 100×100, with the same ratio.
     *
     * @return array{int<1, max>, int<1, max>}
     */
    private static function fit(int $width, int $height): array
    {
        $scale = min(1, ThumbHash::MAX_SIZE / max($width, $height, 1));

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }
}
