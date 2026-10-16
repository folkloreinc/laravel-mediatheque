<?php

namespace Folklore\Mediatheque\Tests\Feature\Images;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Sources\SourceResolvers;
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
}
