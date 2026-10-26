<?php

namespace Folklore\Mediatheque\Tests\Feature\Images;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Testing\Contracts\SourceResolverContractTests;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Images\MediaResolver;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;
use PHPUnit\Framework\Attributes\Test;

class MediaResolverTest extends DatabaseTestCase
{
    use CreatesMedia;
    use SourceResolverContractTests;

    protected function resolver(): SourceResolver
    {
        return app(MediaResolver::class);
    }

    private ?Media $reference = null;

    protected function reference(): mixed
    {
        $this->reference ??= $this->image();

        return 'media://'.$this->reference->uid;
    }

    protected function missingKey(): string
    {
        return 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function image(array $attributes = [], ?string $checksum = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90'): Media
    {
        $media = $this->createMedia(['type' => 'image', 'metadata' => ['thumbhash' => 'HBkSHYSIeHiPiHh8eJd4eTN0EEQG'], ...$attributes]);
        $this->createFile($media, 'original', ['mime' => 'image/jpeg', 'width' => 800, 'height' => 600, 'checksum' => $checksum, 'path' => 'image/'.$media->uid.'/original.jpg']);

        return $media;
    }

    private function video(): Media
    {
        $media = $this->createMedia(['type' => 'video']);
        $this->createFile($media, 'original', ['mime' => 'video/mp4', 'checksum' => str_repeat('0', 64)]);
        $this->createFile($media, 'thumbnails', ['index' => 0, 'role' => 'thumbnail', 'mime' => 'image/jpeg', 'checksum' => str_repeat('1', 64), 'path' => 'video/thumbnails-0.jpg']);
        $this->createFile($media, 'thumbnails', ['index' => 1, 'role' => 'thumbnail', 'mime' => 'image/jpeg', 'checksum' => str_repeat('2', 64), 'path' => 'video/thumbnails-1.jpg']);
        $this->createFile($media, 'poster', ['role' => 'poster', 'mime' => 'image/jpeg', 'checksum' => str_repeat('3', 64), 'width' => 1920, 'height' => 1080, 'path' => 'video/poster.jpg']);

        return $media;
    }

    #[Test]
    public function an_image_resolves_to_its_original_with_its_checksum_as_version(): void
    {
        $media = $this->image();

        $source = $this->resolver()->resolve($media);

        $this->assertSame(['media', $media->uid, 'a1b2c3d4'], [$source->resolver, $source->key, $source->version]);
        $this->assertSame(['public', 'image/'.$media->uid.'/original.jpg', 800, 600, 'image/jpeg'], [$source->disk, $source->path, $source->width, $source->height, $source->mime]);
        $this->assertSame('HBkSHYSIeHiPiHh8eJd4eTN0EEQG', $source->placeholder);
        $this->assertFalse($source->isPrivate());
    }

    #[Test]
    public function a_video_resolves_to_its_poster_and_its_files_by_handle_and_index(): void
    {
        $media = $this->video();

        $this->assertSame(['video/poster.jpg', '33333333'], [$this->resolver()->resolve($media)->path, $this->resolver()->resolve($media)->version]);
        $this->assertSame('video/thumbnails-1.jpg', $this->resolver()->resolveKey($media->uid.'/thumbnails/1')->path);
        $this->assertSame('video/thumbnails-0.jpg', $this->resolver()->resolveKey($media->uid.'/thumbnails')->path);
        $this->assertSame($media->uid.'/thumbnails/1', $this->resolver()->resolve($media->uid.'/thumbnails/1')->key);
    }

    #[Test]
    public function only_image_files_resolve(): void
    {
        $media = $this->video();

        foreach ([$media->uid.'/original', $media->uid.'/thumbnails/7', $media->uid.'/h264'] as $key) {
            try {
                $this->resolver()->resolveKey($key);
                $this->fail("The key {$key} was resolved.");
            } catch (SourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_file_without_a_checksum_yet_does_not_resolve(): void
    {
        $media = $this->image(['status' => MediaStatus::Pending], checksum: null);

        $this->expectException(SourceNotFound::class);

        $this->resolver()->resolve($media);
    }

    #[Test]
    public function pending_and_failed_media_resolve_to_the_images_they_have(): void
    {
        $this->assertSame('a1b2c3d4', $this->resolver()->resolve($this->image(['status' => MediaStatus::Pending]))->version);
        $this->assertSame('a1b2c3d4', $this->resolver()->resolve($this->image(['status' => MediaStatus::Failed]))->version);
    }

    #[Test]
    public function a_deleted_media_does_not_resolve(): void
    {
        $media = $this->image();
        $media->delete();

        $this->expectException(SourceNotFound::class);

        $this->resolver()->resolveKey($media->uid);
    }

    #[Test]
    public function a_key_resolves_whatever_the_application_scopes(): void
    {
        config(['mediatheque.models.media' => TenantMedia::class]);
        $media = $this->image();
        TenantMedia::$scoped = true;

        try {
            $this->assertSame($media->uid, $this->resolver()->resolveKey($media->uid)->key);
        } finally {
            TenantMedia::$scoped = false;
        }
    }

    #[Test]
    public function a_private_media_is_a_private_source(): void
    {
        $this->assertTrue($this->resolver()->resolve($this->image(['visibility' => Visibility::Private]))->isPrivate());
    }

    #[Test]
    public function new_content_gets_a_new_version(): void
    {
        $media = $this->image();
        $before = $this->resolver()->resolve($media)->version;

        $original = $media->original();
        $this->assertInstanceOf(File::class, $original);
        $original->forceFill(['checksum' => 'ffff'.str_repeat('0', 60)])->save();

        $this->assertNotSame($before, $this->resolver()->resolveKey($media->uid)->version);
    }

    #[Test]
    public function supports_checks_the_form_without_querying(): void
    {
        $uid = 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P';

        $this->assertTrue($this->resolver()->supports($uid));
        $this->assertTrue($this->resolver()->supports('media://'.$uid));
        $this->assertTrue($this->resolver()->supports($uid.'/thumbnails/2'));
        $this->assertTrue($this->resolver()->supports(MediaReference::uid($uid)));
        $this->assertTrue($this->resolver()->supports(new Media));
        $this->assertFalse($this->resolver()->supports('medias://12'), 'The 1.x form comes with the compatibility layer.');
        $this->assertFalse($this->resolver()->supports(MediaReference::legacy(12)));
        $this->assertFalse($this->resolver()->supports('upl_01JAB3QX5Z8Y7W6V5T4S3R2Q1P'));
        $this->assertFalse($this->resolver()->supports('photos/cat.jpg'));
        $this->assertFalse($this->resolver()->supports($uid.'/thumbnails/2/x'));
    }

    #[Test]
    public function it_is_registered_in_laravel_image_resolvers(): void
    {
        $this->assertInstanceOf(MediaResolver::class, app(SourceResolvers::class)->named('media'));
    }

    /**
     * The edits of an image, as Panneau 5 stores them.
     *
     * @return array<string, mixed>
     */
    private static function transform(): array
    {
        return [
            'crop' => ['x' => 0, 'y' => 120, 'width' => 800, 'height' => 450],
            'rotate' => 90,
            'flipX' => true,
            'flipY' => false,
            'focalPoint' => ['x' => 0.3, 'y' => 0.6],
        ];
    }

    #[Test]
    public function an_image_resolves_with_its_orientation(): void
    {
        $media = $this->image();
        $this->assertNull($this->resolver()->resolve($media)->orientation);

        $original = $media->original();
        $this->assertInstanceOf(File::class, $original);
        $original->forceFill(['metadata' => ['orientation' => 6]])->save();

        $this->assertSame(6, $this->resolver()->resolveKey($media->uid)->orientation);
    }

    #[Test]
    public function an_original_without_its_orientation_takes_the_media_s(): void
    {
        $media = $this->image(['metadata' => ['orientation' => 8]]);

        $this->assertSame(8, $this->resolver()->resolve($media)->orientation);
        $this->assertNull($this->resolver()->resolve($this->video())->orientation, 'A poster is upright.');
    }

    #[Test]
    public function an_image_resolves_with_the_crop_rotation_and_focal_point_of_its_transform(): void
    {
        $media = $this->image(['transform' => self::transform()]);

        $source = $this->resolver()->resolveKey($media->uid);

        $this->assertSame([0, 120, 800, 450], [$source->transform?->crop?->x, $source->transform?->crop?->y, $source->transform?->crop?->width, $source->transform?->crop?->height]);
        $this->assertSame(90, $source->transform?->rotate);
        $this->assertSame([0.3, 0.6], [$source->focalPoint?->x, $source->focalPoint?->y]);
        $this->assertSame('a1b2c3d4', $source->version, 'The version stays the file\'s: laravel-image adds the edits.');
        $this->assertSame(90, $this->resolver()->resolveKey($media->uid.'/original')->transform?->rotate);
    }

    #[Test]
    public function an_image_without_a_transform_resolves_without_edits(): void
    {
        $source = $this->resolver()->resolve($this->image());

        $this->assertNull($source->transform);
        $this->assertNull($source->focalPoint);
    }

    #[Test]
    public function the_transform_of_a_media_does_not_apply_to_a_video_s_poster(): void
    {
        $media = $this->video();
        $media->forceFill(['transform' => self::transform()])->save();

        $source = $this->resolver()->resolveKey($media->uid);

        $this->assertSame('video/poster.jpg', $source->path);
        $this->assertNull($source->transform);
        $this->assertNull($source->focalPoint);
    }

    #[Test]
    public function the_flips_of_a_transform_reach_the_source(): void
    {
        $media = $this->image(['transform' => ['flipX' => true, 'flipY' => true]]);

        $this->assertSame(Flip::Both, $this->resolver()->resolve($media)->transform?->flip);
    }

    #[Test]
    public function editing_an_image_changes_the_version_of_its_urls(): void
    {
        $media = $this->image();
        $before = $this->resolver()->resolve($media)->urlVersion();

        $media->forceFill(['transform' => self::transform()])->save();
        $edited = $this->resolver()->resolveKey($media->uid);

        $this->assertSame('a1b2c3d4', $before);
        $this->assertSame('a1b2c3d4', $edited->version);
        $this->assertNotSame($before, $edited->urlVersion());
        $this->assertStringStartsWith('a1b2c3d4-', (string) $edited->urlVersion());

        $media->forceFill(['transform' => [...self::transform(), 'focalPoint' => ['x' => 0.5, 'y' => 0.5]]])->save();
        $this->assertNotSame($edited->urlVersion(), $this->resolver()->resolveKey($media->uid)->urlVersion(), 'Moving the focal point gives new URLs too.');
    }
}
