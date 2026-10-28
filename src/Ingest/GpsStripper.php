<?php

namespace Folklore\Mediatheque\Ingest;

use RuntimeException;

/**
 * Removes the location from an image's metadata, without re-encoding it
 * (docs/decisions/0006): the entries of the EXIF GPS IFD, and the XMP
 * properties whose name starts with `GPS` in any namespace
 * (`exif:GPSLatitude`, `drone-dji:GpsLongitude`).
 *
 * The bytes are blanked where they are: the GPS IFD is emptied and its
 * values zeroed, and the XMP properties become spaces, so the file keeps its
 * length and its structure, every other tag (the orientation, the camera,
 * the date) and every pixel. A PNG chunk gets its CRC again.
 *
 * It reads JPEG (APP1), PNG (`eXIf`, uncompressed XMP `iTXt`), WebP (`EXIF`,
 * `XMP `), TIFF, and HEIF and AVIF (their `Exif` and XMP items). Metadata it
 * can't parse is left as it is.
 */
final class GpsStripper
{
    public const array MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/tiff', 'image/heic', 'image/heif', 'image/avif'];

    private const int GPS_IFD = 0x8825;

    private const string XMP_PROPERTY = '[A-Za-z][\w.-]*:[Gg][Pp][Ss][A-Za-z]*';

    /** Bytes per value of each TIFF field type */
    private const array TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8, 13 => 4];

    public static function supports(string $mime): bool
    {
        return in_array($mime, self::MIMES, true);
    }

    /**
     * What to write over the file to remove its location: bytes by offset,
     * none when it has no location. Reads the file only.
     *
     * @return array<int, string>
     */
    public function patches(string $path, string $mime): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $file = new BinaryFile($handle, (int) filesize($path));

            return match ($mime) {
                'image/jpeg' => $this->jpeg($file),
                'image/png' => $this->png($file),
                'image/webp' => $this->webp($file),
                'image/tiff' => $this->tiff($file, 0, $file->size),
                'image/heic', 'image/heif', 'image/avif' => $this->heif($file),
                default => [],
            };
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write patches over a file.
     *
     * @param  array<int, string>  $patches
     */
    public static function apply(string $path, array $patches): void
    {
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException(sprintf('The file "%s" can\'t be written.', $path));
        }

        try {
            foreach ($patches as $offset => $bytes) {
                fseek($handle, $offset);
                fwrite($handle, $bytes);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<int, string>
     */
    private function jpeg(BinaryFile $file): array
    {
        if ($file->read(0, 2) !== "\xFF\xD8") {
            return [];
        }

        $patches = [];
        $position = 2;

        while ($position + 4 <= $file->size) {
            if ($file->read($position, 1) !== "\xFF") {
                break;
            }
            $marker = ord($file->read($position + 1, 1));
            if ($marker === 0xFF) {
                $position++;

                continue;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $position += 2;

                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $length = $file->u16be($position + 2);
            $data = $position + 4;
            $end = $position + 2 + $length;
            if ($length < 2 || $end > $file->size) {
                break;
            }

            if ($marker === 0xE1) {
                $header = $file->read($data, 29);
                if (str_starts_with($header, "Exif\0\0")) {
                    $patches += $this->tiff($file, $data + 6, $end - $data - 6);
                } elseif (str_starts_with($header, "http://ns.adobe.com/xap/1.0/\0")) {
                    $patches += $this->xmp($file, $data + 29, $end - $data - 29);
                }
            }

            $position = $end;
        }

        return $patches;
    }

    /**
     * @return array<int, string>
     */
    private function png(BinaryFile $file): array
    {
        if ($file->read(0, 8) !== "\x89PNG\r\n\x1A\n") {
            return [];
        }

        $patches = [];
        $position = 8;

        while ($position + 12 <= $file->size) {
            $length = $file->u32be($position);
            $type = $file->read($position + 4, 4);
            $data = $position + 8;
            if ($data + $length + 4 > $file->size) {
                break;
            }

            $chunk = match ($type) {
                'eXIf' => $this->tiff($file, $data, $length),
                'iTXt' => $this->pngXmp($file, $data, $length),
                default => [],
            };

            if ($chunk !== []) {
                $patches += $chunk;
                // The CRC covers the type and the data, as patched
                $bytes = $type.$file->read($data, $length);
                foreach ($chunk as $offset => $replacement) {
                    $bytes = substr_replace($bytes, $replacement, $offset - $data + 4, strlen($replacement));
                }
                $patches[$data + $length] = pack('N', crc32($bytes));
            }

            if ($type === 'IEND') {
                break;
            }
            $position = $data + $length + 4;
        }

        return $patches;
    }

    /**
     * The XMP packet of an uncompressed `iTXt` chunk.
     *
     * @return array<int, string>
     */
    private function pngXmp(BinaryFile $file, int $data, int $length): array
    {
        $keyword = 'XML:com.adobe.xmp';
        $head = $file->read($data, min($length, 200));
        if (! str_starts_with($head, $keyword."\0") || ($head[strlen($keyword) + 1] ?? "\1") !== "\0") {
            return [];
        }

        // Keyword, null, compression flag and method, language and translated keyword, each null-terminated
        $language = strpos($head, "\0", strlen($keyword) + 3);
        $translated = $language === false ? false : strpos($head, "\0", $language + 1);
        if ($translated === false) {
            return [];
        }

        return $this->xmp($file, $data + $translated + 1, $length - $translated - 1);
    }

    /**
     * @return array<int, string>
     */
    private function webp(BinaryFile $file): array
    {
        if ($file->read(0, 4) !== 'RIFF' || $file->read(8, 4) !== 'WEBP') {
            return [];
        }

        $patches = [];
        $position = 12;

        while ($position + 8 <= $file->size) {
            $type = $file->read($position, 4);
            $length = $file->u32le($position + 4);
            $data = $position + 8;
            if ($data + $length > $file->size) {
                break;
            }

            if ($type === 'EXIF') {
                // Some writers keep the JPEG header
                $skip = $file->read($data, 6) === "Exif\0\0" ? 6 : 0;
                $patches += $this->tiff($file, $data + $skip, $length - $skip);
            } elseif ($type === 'XMP ') {
                $patches += $this->xmp($file, $data, $length);
            }

            $position = $data + $length + ($length % 2);
        }

        return $patches;
    }

    /**
     * The `Exif` and XMP items of a HEIF or AVIF file, found through its
     * `meta` box: `iinf` names them, `iloc` locates them.
     *
     * @return array<int, string>
     */
    private function heif(BinaryFile $file): array
    {
        $meta = $this->box($file, 0, $file->size, 'meta');
        if ($meta === null) {
            return [];
        }

        // `meta` is a full box: version and flags first
        [$start, $end] = [$meta[0] + 4, $meta[1]];
        $iinf = $this->box($file, $start, $end, 'iinf');
        $iloc = $this->box($file, $start, $end, 'iloc');
        if ($iinf === null || $iloc === null) {
            return [];
        }

        $items = $this->heifItems($file, $iinf[0], $iinf[1]);
        $patches = [];

        foreach ($this->heifLocations($file, $iloc[0], $iloc[1]) as $id => [$offset, $length]) {
            $kind = $items[$id] ?? null;
            if ($kind === 'Exif' && $length > 4) {
                $header = $file->u32be($offset);
                if ($header + 4 < $length) {
                    $patches += $this->tiff($file, $offset + 4 + $header, $length - 4 - $header);
                }
            } elseif ($kind === 'xmp') {
                $patches += $this->xmp($file, $offset, $length);
            }
        }

        return $patches;
    }

    /**
     * The items that hold metadata: `Exif`, or `xmp` for an XMP packet.
     *
     * @return array<int, string>
     */
    private function heifItems(BinaryFile $file, int $start, int $end): array
    {
        $version = ord($file->read($start, 1));
        $position = $start + 4 + ($version === 0 ? 2 : 4);
        $items = [];

        while (($box = $this->nextBox($file, $position, $end)) !== null) {
            [$type, $data, $boxEnd] = $box;
            $position = $boxEnd;
            if ($type !== 'infe') {
                continue;
            }

            $infeVersion = ord($file->read($data, 1));
            if ($infeVersion < 2) {
                continue;
            }
            $cursor = $data + 4;
            $id = $infeVersion === 2 ? $file->u16be($cursor) : $file->u32be($cursor);
            $cursor += ($infeVersion === 2 ? 2 : 4) + 2;
            $itemType = $file->read($cursor, 4);

            if ($itemType === 'Exif') {
                $items[$id] = 'Exif';
            } elseif ($itemType === 'mime') {
                $strings = explode("\0", $file->read($cursor + 4, min(256, $boxEnd - $cursor - 4)));
                if (($strings[1] ?? null) === 'application/rdf+xml') {
                    $items[$id] = 'xmp';
                }
            }
        }

        return $items;
    }

    /**
     * Where each item of one extent stored in the file is: offset and length.
     *
     * @return array<int, array{int, int}>
     */
    private function heifLocations(BinaryFile $file, int $start, int $end): array
    {
        $version = ord($file->read($start, 1));
        if ($version > 2) {
            return [];
        }

        $sizes = ord($file->read($start + 4, 1));
        $more = ord($file->read($start + 5, 1));
        [$offsetSize, $lengthSize, $baseSize] = [$sizes >> 4, $sizes & 0x0F, $more >> 4];
        $indexSize = $version > 0 ? $more & 0x0F : 0;
        $position = $start + 6;
        $count = $version < 2 ? $file->u16be($position) : $file->u32be($position);
        $position += $version < 2 ? 2 : 4;
        $locations = [];

        for ($i = 0; $i < $count && $position < $end; $i++) {
            $id = $version < 2 ? $file->u16be($position) : $file->u32be($position);
            $position += $version < 2 ? 2 : 4;
            $method = 0;
            if ($version > 0) {
                $method = $file->u16be($position) & 0x0F;
                $position += 2;
            }
            $position += 2;
            $base = $file->uint($position, $baseSize);
            $position += $baseSize;
            $extents = $file->u16be($position);
            $position += 2;

            $first = null;
            for ($e = 0; $e < $extents; $e++) {
                $position += $indexSize;
                $offset = $file->uint($position, $offsetSize);
                $length = $file->uint($position + $offsetSize, $lengthSize);
                $position += $offsetSize + $lengthSize;
                $first ??= [$base + $offset, $length];
            }

            if ($method === 0 && $extents === 1 && $first !== null && $file->size >= $first[0] + $first[1]) {
                $locations[$id] = $first;
            }
        }

        return $locations;
    }

    /**
     * The first box of a type between two offsets: where its content starts
     * and where it ends.
     *
     * @return array{int, int}|null
     */
    private function box(BinaryFile $file, int $start, int $end, string $type): ?array
    {
        $position = $start;
        while (($box = $this->nextBox($file, $position, $end)) !== null) {
            if ($box[0] === $type) {
                return [$box[1], $box[2]];
            }
            $position = $box[2];
        }

        return null;
    }

    /**
     * The box at an offset: its type, where its content starts and where it
     * ends.
     *
     * @return array{string, int, int}|null
     */
    private function nextBox(BinaryFile $file, int $position, int $end): ?array
    {
        if ($position + 8 > $end) {
            return null;
        }

        $size = $file->u32be($position);
        $type = $file->read($position + 4, 4);
        $data = $position + 8;

        if ($size === 1) {
            $size = $file->uint($data, 8);
            $data += 8;
        } elseif ($size === 0) {
            $size = $end - $position;
        }

        return $size < $data - $position || $position + $size > $end ? null : [$type, $data, $position + $size];
    }

    /**
     * Empty the GPS IFD of a TIFF structure, and zero the values it points
     * to.
     *
     * @return array<int, string>
     */
    private function tiff(BinaryFile $file, int $base, int $length): array
    {
        if ($length < 8) {
            return [];
        }

        $order = $file->read($base, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return [];
        }
        $little = $order === 'II';
        $u16 = fn (int $at) => $little ? $file->u16le($base + $at) : $file->u16be($base + $at);
        $u32 = fn (int $at) => $little ? $file->u32le($base + $at) : $file->u32be($base + $at);

        $ifd = $u32(4);
        if ($ifd < 8 || $ifd + 2 > $length) {
            return [];
        }

        $gps = null;
        $entries = $u16($ifd);
        for ($i = 0; $i < $entries && $ifd + 2 + 12 * ($i + 1) <= $length; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($u16($entry) === self::GPS_IFD) {
                $gps = $u32($entry + 8);
                break;
            }
        }

        if ($gps === null || $gps < 8 || $gps + 2 > $length) {
            return [];
        }

        $count = $u16($gps);
        if ($count === 0 || $gps + 2 + 12 * $count > $length) {
            return [];
        }

        $patches = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = $gps + 2 + 12 * $i;
            $size = (self::TYPE_SIZES[$u16($entry + 2)] ?? 0) * $u32($entry + 4);
            if ($size > 4) {
                $value = $u32($entry + 8);
                if ($value >= 8 && $value + $size <= $length) {
                    $patches[$base + $value] = str_repeat("\0", $size);
                }
            }
        }

        // No entries: readers find an empty IFD, and its next IFD at 0
        $patches[$base + $gps] = str_repeat("\0", 2 + 12 * $count);

        return $patches;
    }

    /**
     * Blank the GPS properties of an XMP packet with spaces.
     *
     * @return array<int, string>
     */
    private function xmp(BinaryFile $file, int $start, int $length): array
    {
        if ($length <= 0) {
            return [];
        }

        $xml = $file->read($start, $length);
        $property = self::XMP_PROPERTY;
        $patterns = [
            "/\\s{$property}\\s*=\\s*(\"[^\"]*\"|'[^']*')/",
            "/<({$property})\\b[^>]*\\/>/",
            "/<({$property})\\b[^>]*>.*?<\\/\\1\\s*>/s",
        ];

        $blanked = $xml;
        foreach ($patterns as $pattern) {
            $blanked = (string) preg_replace_callback($pattern, fn (array $match) => str_repeat(' ', strlen($match[0])), $blanked);
        }

        return $blanked === $xml ? [] : [$start => $blanked];
    }
}
