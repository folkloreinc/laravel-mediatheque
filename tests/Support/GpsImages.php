<?php

namespace Folklore\Mediatheque\Tests\Support;

use RuntimeException;

/**
 * Images whose EXIF and XMP hold a location, built with GD: the camera
 * (`Make`), the orientation (6) and a GPS IFD whose values are easy to find
 * in the bytes, and XMP GPS properties as attributes and as elements.
 */
final class GpsImages
{
    /** The GPS IFD's map datum, an ASCII value stored out of line */
    public const string DATUM = 'WGS-84-SECRET';

    public const string MAKE = 'FolkloreCam';

    /** The XMP latitude */
    public const string XMP_LATITUDE = '45,30.2N';

    /** The DJI XMP longitude, an element */
    public const string XMP_LONGITUDE = '-73.5761';

    /**
     * The latitude, as stored: three big-endian rationals.
     */
    public static function latitude(bool $little = false): string
    {
        return self::rationals([[45, 1], [30, 1], [1234, 100]], $little);
    }

    public static function jpeg(bool $xmp = true): string
    {
        $jpeg = self::render('imagejpeg');
        $segments = self::segment(0xE1, "Exif\0\0".self::exif())
            .($xmp ? self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".self::xmp()) : '');

        return substr($jpeg, 0, 2).$segments.substr($jpeg, 2);
    }

    public static function jpegWithoutExif(): string
    {
        return self::render('imagejpeg');
    }

    public static function png(): string
    {
        $png = self::render('imagepng');
        $xmp = "XML:com.adobe.xmp\0\0\0\0\0".self::xmp();
        // After the signature and IHDR
        $head = 8 + 25;

        return substr($png, 0, $head).self::chunk('eXIf', self::exif(little: true)).self::chunk('iTXt', $xmp).substr($png, $head);
    }

    public static function webp(): string
    {
        $webp = self::render('imagewebp');
        if (substr($webp, 12, 4) !== 'VP8 ') {
            throw new RuntimeException('GD wrote a WebP that is not lossy.');
        }

        $size = getimagesizefromstring($webp) ?: [1, 1];
        $vp8x = pack('C', 0x08 | 0x04)."\0\0\0".substr(pack('V', $size[0] - 1), 0, 3).substr(pack('V', $size[1] - 1), 0, 3);
        $body = 'WEBP'.self::riffChunk('VP8X', $vp8x).substr($webp, 12).self::riffChunk('EXIF', self::exif()).self::riffChunk('XMP ', self::xmp());

        return 'RIFF'.pack('V', strlen($body)).$body;
    }

    /**
     * A one-pixel grey TIFF, with its location in its first IFD.
     */
    public static function tiff(): string
    {
        $build = fn (int $strip) => self::exif(little: false, image: [
            [256, 3, [1]], [257, 3, [1]], [258, 3, [8]], [259, 3, [1]], [262, 3, [1]],
            [273, 4, [$strip]], [277, 3, [1]], [278, 3, [1]], [279, 4, [1]],
        ]);
        $length = strlen($build(0));

        return $build($length)."\x80";
    }

    /**
     * A TIFF structure: IFD0 with the camera, the orientation, the GPS IFD
     * and the image's own entries, then the GPS IFD, then values out of line.
     *
     * @param  list<array{int, int, list<int>|list<array{int, int}>|string}>  $image
     */
    public static function exif(bool $little = false, array $image = []): string
    {
        $ifd0 = [...$image, [271, 2, self::MAKE."\0"], [274, 3, [6]], [0x8825, 4, [0]]];
        usort($ifd0, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $gps = [
            [0, 1, [2, 3, 0, 0]],
            [1, 2, "N\0"],
            [2, 5, [[45, 1], [30, 1], [1234, 100]]],
            [3, 2, "W\0"],
            [4, 5, [[73, 1], [34, 1], [5678, 100]]],
            [18, 2, self::DATUM."\0"],
        ];

        $ifd0Offset = 8;
        $gpsOffset = $ifd0Offset + 2 + 12 * count($ifd0) + 4;
        $dataOffset = $gpsOffset + 2 + 12 * count($gps) + 4;
        $data = '';

        $header = ($little ? 'II' : 'MM').self::u16(42, $little).self::u32($ifd0Offset, $little);

        return $header
            .self::ifd($ifd0, $little, $gpsOffset, $dataOffset, $data)
            .self::ifd($gps, $little, $gpsOffset, $dataOffset, $data)
            .$data;
    }

    /**
     * An IFD, whose values longer than 4 bytes are appended to `$data`.
     *
     * @param  list<array{int, int, list<int>|list<array{int, int}>|string}>  $entries
     */
    private static function ifd(array $entries, bool $little, int $gpsOffset, int $dataOffset, string &$data): string
    {
        $bytes = self::u16(count($entries), $little);
        foreach ($entries as [$tag, $type, $values]) {
            $value = $tag === 0x8825 ? self::u32($gpsOffset, $little) : self::value($type, $values, $little);
            $count = is_string($values) ? strlen($values) : count($values);
            if (strlen($value) > 4) {
                $offset = $dataOffset + strlen($data);
                $data .= $value.(strlen($value) % 2 ? "\0" : '');
                $value = self::u32($offset, $little);
            }
            $bytes .= self::u16($tag, $little).self::u16($type, $little).self::u32($count, $little).str_pad($value, 4, "\0");
        }

        return $bytes.self::u32(0, $little);
    }

    public static function xmp(): string
    {
        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:tiff="http://ns.adobe.com/tiff/1.0/"'
            .' xmlns:drone-dji="http://www.dji.com/drone-dji/1.0/"'
            .' tiff:Make="'.self::MAKE.'" exif:GPSLatitude="'.self::XMP_LATITUDE.'" exif:GPSVersionID="2.3.0.0">'
            .'<drone-dji:GpsLongitude>'.self::XMP_LONGITUDE.'</drone-dji:GpsLongitude>'
            .'</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /**
     * @param  'imagejpeg'|'imagepng'|'imagewebp'  $function
     */
    private static function render(string $function): string
    {
        $image = imagecreatetruecolor(4, 3);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 20));
        ob_start();
        $function($image);

        return (string) ob_get_clean();
    }

    private static function segment(int $marker, string $data): string
    {
        return "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function riffChunk(string $type, string $data): string
    {
        return $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
    }

    /**
     * @param  list<int>|list<array{int, int}>|string  $values
     */
    private static function value(int $type, array|string $values, bool $little): string
    {
        if (is_string($values)) {
            return $values;
        }

        return match ($type) {
            1 => implode('', array_map(fn (mixed $value) => chr(is_int($value) ? $value : 0), $values)),
            3 => implode('', array_map(fn (mixed $value) => self::u16(is_int($value) ? $value : 0, $little), $values)),
            4 => implode('', array_map(fn (mixed $value) => self::u32(is_int($value) ? $value : 0, $little), $values)),
            5 => self::rationals(array_values(array_filter($values, 'is_array')), $little),
            default => '',
        };
    }

    /**
     * @param  list<array{int, int}>  $values
     */
    private static function rationals(array $values, bool $little): string
    {
        return implode('', array_map(fn (array $value) => self::u32($value[0], $little).self::u32($value[1], $little), $values));
    }

    private static function u16(int $value, bool $little): string
    {
        return pack($little ? 'v' : 'n', $value);
    }

    private static function u32(int $value, bool $little): string
    {
        return pack($little ? 'V' : 'N', $value);
    }
}
