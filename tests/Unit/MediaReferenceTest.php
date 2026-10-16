<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Exceptions\InvalidReference;
use Folklore\Mediatheque\References\MediaReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MediaReferenceTest extends TestCase
{
    private const string UID = 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P';

    #[Test]
    public function it_parses_a_media_reference_by_uid(): void
    {
        $reference = MediaReference::parse('media://'.self::UID);

        $this->assertSame(self::UID, $reference->uid);
        $this->assertNull($reference->id);
        $this->assertFalse($reference->isLegacy());
        $this->assertSame('media://'.self::UID, (string) $reference);
    }

    #[Test]
    public function it_parses_a_1x_reference_by_internal_id(): void
    {
        $reference = MediaReference::parse('medias://123');

        $this->assertNull($reference->uid);
        $this->assertSame(123, $reference->id);
        $this->assertTrue($reference->isLegacy());
        $this->assertSame('medias://123', (string) $reference);
    }

    #[Test]
    public function it_builds_references(): void
    {
        $this->assertTrue(MediaReference::uid(self::UID)->equals(MediaReference::parse('media://'.self::UID)));
        $this->assertTrue(MediaReference::legacy(42)->equals(MediaReference::parse('medias://42')));
        $this->assertFalse(MediaReference::legacy(42)->equals(MediaReference::legacy(43)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'a uid without scheme' => [self::UID];
        yield 'an integer' => ['123'];
        yield 'an invalid uid' => ['media://med_123'];
        yield 'a uid in a 1.x reference' => ['medias://'.self::UID];
        yield 'an id in a 2.0 reference' => ['media://123'];
        yield 'zero' => ['medias://0'];
        yield 'a negative id' => ['medias://-1'];
        yield 'a leading zero' => ['medias://0123'];
        yield 'an id beyond 64 bits' => ['medias://99999999999999999999'];
        yield 'a path after the uid' => ['media://'.self::UID.'/h264'];
        yield 'a query' => ['media://'.self::UID.'?x=1'];
        yield 'surrounding spaces' => [' media://'.self::UID];
        yield 'another scheme' => ['https://example.com/'.self::UID];
        yield 'an uppercase scheme' => ['MEDIA://'.self::UID];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function it_refuses_what_is_not_a_reference(string $value): void
    {
        $this->assertNull(MediaReference::tryParse($value));
        $this->assertFalse(MediaReference::isReference($value));

        $this->expectException(InvalidReference::class);
        MediaReference::parse($value);
    }

    #[Test]
    public function it_refuses_an_invalid_uid_or_id(): void
    {
        $this->expectException(InvalidReference::class);

        MediaReference::legacy(0);
    }
}
