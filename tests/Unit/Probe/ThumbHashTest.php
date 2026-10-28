<?php

namespace Folklore\Mediatheque\Tests\Unit\Probe;

use Folklore\Mediatheque\Probe\ThumbHash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The encoder gives the bytes of evanw's reference implementation.
 */
class ThumbHashTest extends TestCase
{
    /**
     * The values of `rgbaToThumbHash()` in the `thumbhash` package 0.1.1, on
     * npm, for the pixels of pixels().
     *
     * @return iterable<string, array{int, int, bool, string}>
     */
    public static function references(): iterable
    {
        yield 'landscape' => [8, 6, false, 'lQoGLZIQTGlFd5ZweHmKahdHUIDY'];
        yield 'portrait' => [6, 9, false, 'FwsKFRJgd1JqaFk4lqWnh0AQA5Z3'];
        yield 'square, with alpha' => [10, 10, true, 'GOqFFQYnOMR3toaJBy941mCYIFo3a4Z4dw=='];
        yield 'one pixel' => [1, 1, false, 'AAgCBwAAAAAAAAAAAAAAAAAAAAAAAAAA'];
        yield 'the largest landscape' => [100, 75, false, '4PcFDYCIiGhoaAh2dnV4d1f2joEB'];
        yield 'the largest portrait, with alpha' => [33, 100, true, '3/eBAgAHd4eCcIdPQ1cQpzkKdoWTcic='];
    }

    #[Test]
    #[DataProvider('references')]
    public function it_encodes_like_the_reference_implementation(int $width, int $height, bool $alpha, string $expected): void
    {
        $this->assertSame($expected, ThumbHash::encode($width, $height, self::pixels($width, $height, $alpha)));
    }

    #[Test]
    public function an_image_larger_than_100_pixels_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThumbHash::encode(101, 1, self::pixels(101, 1, false));
    }

    #[Test]
    public function pixels_that_do_not_match_the_size_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ThumbHash::encode(2, 2, self::pixels(2, 1, false));
    }

    /**
     * A pattern that varies on both axes, in every channel.
     *
     * @return list<int>
     */
    private static function pixels(int $width, int $height, bool $alpha): array
    {
        $rgba = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                array_push($rgba, ($x * 37 + $y * 11) % 256, ($x * 13 + $y * 59) % 256, ($x * 7 + $y * 3) % 256, $alpha ? ($x * 25 + $y * 9) % 256 : 255);
            }
        }

        return $rgba;
    }
}
