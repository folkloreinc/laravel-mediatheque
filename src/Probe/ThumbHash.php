<?php

namespace Folklore\Mediatheque\Probe;

use InvalidArgumentException;

/**
 * The ThumbHash encoder (https://evanw.github.io/thumbhash/), ported from
 * evanw's reference implementation (MIT): the same pixels give the same
 * bytes. Rounding follows JavaScript's `Math.round()`.
 */
final class ThumbHash
{
    /** The largest side it encodes: more is slower, with no benefit */
    public const int MAX_SIZE = 100;

    /**
     * The ThumbHash of an image of at most 100×100, in base64.
     *
     * @param  list<int>  $rgba  Four bytes per pixel, row by row
     */
    public static function encode(int $width, int $height, array $rgba): string
    {
        return base64_encode(self::bytes($width, $height, $rgba));
    }

    /**
     * @param  list<int>  $rgba
     */
    public static function bytes(int $width, int $height, array $rgba): string
    {
        if ($width < 1 || $height < 1 || $width > self::MAX_SIZE || $height > self::MAX_SIZE) {
            throw new InvalidArgumentException(sprintf('%d×%d doesn\'t fit in %d×%d.', $width, $height, self::MAX_SIZE, self::MAX_SIZE));
        }
        if (count($rgba) !== $width * $height * 4) {
            throw new InvalidArgumentException(sprintf('A %d×%d image has %d RGBA bytes.', $width, $height, $width * $height * 4));
        }

        $pixels = $width * $height;

        // The average color
        $averageR = $averageG = $averageB = $averageA = 0.0;
        for ($i = 0, $j = 0; $i < $pixels; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $averageR += $alpha / 255 * $rgba[$j];
            $averageG += $alpha / 255 * $rgba[$j + 1];
            $averageB += $alpha / 255 * $rgba[$j + 2];
            $averageA += $alpha;
        }
        if ($averageA > 0) {
            $averageR /= $averageA;
            $averageG /= $averageA;
            $averageB /= $averageA;
        }

        $hasAlpha = $averageA < $pixels;
        // Fewer luminance bits when there is alpha
        $limit = $hasAlpha ? 5 : 7;
        $lx = max(1, self::round($limit * $width / max($width, $height)));
        $ly = max(1, self::round($limit * $height / max($width, $height)));

        // RGBA to LPQA: luminance, yellow - blue, red - green and alpha,
        // composited over the average color
        $l = $p = $q = $a = [];
        for ($i = 0, $j = 0; $i < $pixels; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $r = $averageR * (1 - $alpha) + $alpha / 255 * $rgba[$j];
            $g = $averageG * (1 - $alpha) + $alpha / 255 * $rgba[$j + 1];
            $b = $averageB * (1 - $alpha) + $alpha / 255 * $rgba[$j + 2];
            $l[$i] = ($r + $g + $b) / 3;
            $p[$i] = ($r + $g) / 2 - $b;
            $q[$i] = $r - $g;
            $a[$i] = $alpha;
        }

        [$lDc, $lAc, $lScale] = self::channel($l, max(3, $lx), max(3, $ly), $width, $height);
        [$pDc, $pAc, $pScale] = self::channel($p, 3, 3, $width, $height);
        [$qDc, $qAc, $qScale] = self::channel($q, 3, 3, $width, $height);
        [$aDc, $aAc, $aScale] = $hasAlpha ? self::channel($a, 5, 5, $width, $height) : [0.0, [], 0.0];

        // The constants
        $isLandscape = $width > $height;
        $header24 = self::round(63 * $lDc)
            | (self::round(31.5 + 31.5 * $pDc) << 6)
            | (self::round(31.5 + 31.5 * $qDc) << 12)
            | (self::round(31 * $lScale) << 18)
            | ((int) $hasAlpha << 23);
        $header16 = ($isLandscape ? $ly : $lx)
            | (self::round(63 * $pScale) << 3)
            | (self::round(63 * $qScale) << 9)
            | ((int) $isLandscape << 15);
        $hash = [$header24 & 255, ($header24 >> 8) & 255, $header24 >> 16, $header16 & 255, $header16 >> 8];
        if ($hasAlpha) {
            $hash[] = self::round(15 * $aDc) | (self::round(15 * $aScale) << 4);
        }

        // The varying factors, two per byte
        $start = count($hash);
        $index = 0;
        foreach ($hasAlpha ? [$lAc, $pAc, $qAc, $aAc] : [$lAc, $pAc, $qAc] as $ac) {
            foreach ($ac as $factor) {
                $byte = $start + ($index >> 1);
                $hash[$byte] = ($hash[$byte] ?? 0) | (self::round(15 * $factor) << (($index & 1) << 2));
                $index++;
            }
        }

        return pack('C*', ...$hash);
    }

    /**
     * The DCT of a channel: its constant term, its varying terms normalized
     * between 0 and 1, and their scale.
     *
     * @param  array<int, float>  $channel
     * @return array{float, list<float>, float}
     */
    private static function channel(array $channel, int $nx, int $ny, int $width, int $height): array
    {
        $dc = 0.0;
        $ac = [];
        $scale = 0.0;
        $fx = [];

        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = 0; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $f = 0.0;
                for ($x = 0; $x < $width; $x++) {
                    $fx[$x] = cos(M_PI / $width * $cx * ($x + 0.5));
                }
                for ($y = 0; $y < $height; $y++) {
                    $fy = cos(M_PI / $height * $cy * ($y + 0.5));
                    for ($x = 0; $x < $width; $x++) {
                        $f += $channel[$x + $y * $width] * $fx[$x] * $fy;
                    }
                }
                $f /= $width * $height;

                if ($cx > 0 || $cy > 0) {
                    $ac[] = $f;
                    $scale = max($scale, abs($f));
                } else {
                    $dc = $f;
                }
            }
        }

        if ($scale > 0) {
            $ac = array_map(fn (float $factor) => 0.5 + 0.5 / $scale * $factor, $ac);
        }

        return [$dc, $ac, $scale];
    }

    /**
     * JavaScript's `Math.round()`: halves round up.
     */
    private static function round(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
