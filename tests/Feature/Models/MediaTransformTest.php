<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Exceptions\InvalidTransform;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `transform` of a media, checked against Panneau 5's `MediaTransform`
 * when it is written (docs/decisions/0022).
 */
class MediaTransformTest extends DatabaseTestCase
{
    use CreatesMedia;

    #[Test]
    public function it_is_stored_outside_the_metadata_with_the_keys_it_was_given(): void
    {
        $media = $this->createMedia(['type' => 'image', 'metadata' => ['width' => 1600]]);

        $media->forceFill(['transform' => ['rotate' => 180, 'focalPoint' => ['x' => 1, 'y' => 0], 'zoom' => 2]])->save();

        $fresh = Media::query()->findOrFail($media->id);
        // In any order: MySQL and PostgreSQL's jsonb sort an object's keys
        $this->assertEquals(['rotate' => 180, 'focalPoint' => ['x' => 1, 'y' => 0], 'zoom' => 2], $fresh->transform());
        $this->assertSame(['width' => 1600], $fresh->metadata(), 'The metadata are left as they are.');
        $this->assertNotNull(DB::table('mediatheque_medias')->where('id', $media->id)->value('transform'));
    }

    #[Test]
    public function an_empty_transform_and_null_values_are_valid(): void
    {
        $media = $this->createMedia(['type' => 'image']);

        $media->forceFill(['transform' => []])->save();
        $this->assertSame([], Media::query()->findOrFail($media->id)->transform());

        $media->forceFill(['transform' => ['crop' => null, 'focalPoint' => null, 'rotate' => 0]])->save();
        $this->assertEquals(['crop' => null, 'focalPoint' => null, 'rotate' => 0], Media::query()->findOrFail($media->id)->transform());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTransforms(): iterable
    {
        yield 'a string' => ['{"rotate":90}'];
        yield 'a list' => [[90]];
        yield 'a crop in fractions' => [['crop' => ['x' => 0.1, 'y' => 0, 'width' => 100, 'height' => 100]]];
        yield 'a crop with a negative offset' => [['crop' => ['x' => -1, 'y' => 0, 'width' => 100, 'height' => 100]]];
        yield 'an empty crop' => [['crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 100]]];
        yield 'a crop without its height' => [['crop' => ['x' => 0, 'y' => 0, 'width' => 100]]];
        yield 'a rotation of 45 degrees' => [['rotate' => 45]];
        yield 'a rotation in a string' => [['rotate' => '90']];
        yield 'a flip that is not a boolean' => [['flipX' => 1]];
        yield 'a focal point past the edge' => [['focalPoint' => ['x' => 1.2, 'y' => 0.5]]];
        yield 'a focal point without y' => [['focalPoint' => ['x' => 0.5]]];
    }

    #[Test]
    #[DataProvider('invalidTransforms')]
    public function a_transform_of_another_shape_is_refused(mixed $transform): void
    {
        $media = $this->createMedia(['type' => 'image']);

        $this->expectException(InvalidTransform::class);

        $media->forceFill(['transform' => $transform]);
    }
}
