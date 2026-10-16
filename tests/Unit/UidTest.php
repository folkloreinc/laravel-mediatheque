<?php

namespace Folklore\Mediatheque\Tests\Unit;

use DateTimeImmutable;
use Folklore\Mediatheque\Exceptions\InvalidUid;
use Folklore\Mediatheque\Support\Uid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UidTest extends TestCase
{
    #[Test]
    public function it_generates_a_prefixed_ulid_of_30_characters(): void
    {
        $uid = Uid::generate('med');

        $this->assertMatchesRegularExpression('/^med_[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $uid);
        $this->assertSame(30, strlen($uid));
        $this->assertTrue(Uid::isValid($uid, 'med'));
        $this->assertSame('med', Uid::prefixOf($uid));
    }

    #[Test]
    public function uids_generated_at_a_given_time_sort_in_that_order(): void
    {
        $older = Uid::generate('med', new DateTimeImmutable('2018-02-05 10:00:00'));
        $newer = Uid::generate('med', new DateTimeImmutable('2023-07-06 10:00:00'));
        $now = Uid::generate('med');

        $this->assertLessThan(0, strcmp($older, $newer));
        $this->assertLessThan(0, strcmp($newer, $now));
    }

    #[Test]
    public function it_generates_unique_uids(): void
    {
        $uids = array_map(fn () => Uid::generate('upl'), range(1, 1000));

        $this->assertCount(1000, array_unique($uids));
    }

    /**
     * @return iterable<string, array{string, string|null, bool}>
     */
    public static function values(): iterable
    {
        yield 'media uid' => ['med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', null, true];
        yield 'with its prefix' => ['med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', 'med', true];
        yield 'with another prefix' => ['med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', 'upl', false];
        yield 'custom prefix' => ['asset_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', null, true];
        yield 'lowercase ULID' => ['med_01jab3qx5z8y7w6v5t4s3r2q1p', null, false];
        yield 'excluded letter' => ['med_01JAB3QX5Z8Y7W6V5T4S3R2Q1U', null, false];
        yield 'beyond the ULID range' => ['med_81JAB3QX5Z8Y7W6V5T4S3R2Q1P', null, false];
        yield 'too short' => ['med_01JAB3QX5Z8Y7W6V5T4S3R2Q1', null, false];
        yield 'no prefix' => ['01JAB3QX5Z8Y7W6V5T4S3R2Q1P', null, false];
        yield 'integer id' => ['123', null, false];
    }

    #[Test]
    #[DataProvider('values')]
    public function it_validates_uids(string $value, ?string $prefix, bool $valid): void
    {
        $this->assertSame($valid, Uid::isValid($value, $prefix));
    }

    #[Test]
    public function it_refuses_an_invalid_prefix(): void
    {
        $this->expectException(InvalidUid::class);

        Uid::generate('Med-');
    }
}
