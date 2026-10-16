<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rules of the Media and File contracts. Every implementation runs them:
 * the fake's in-memory models and the package's Eloquent models.
 */
trait MediaContractTests
{
    /**
     * A media of this type and visibility, with these files, created in this
     * order: each is [handle, index, role].
     *
     * @param  list<array{string, int|null, string|null}>  $files
     * @param  array<string, mixed>  $metadata
     */
    abstract protected function mediaWithFiles(string $type, Visibility $visibility, array $files, array $metadata = []): Media;

    #[Test]
    public function the_original_is_the_file_with_the_original_handle(): void
    {
        $media = $this->mediaWithFiles('video', Visibility::Public, [['h264', null, 'main'], ['original', null, 'original']]);

        $this->assertSame(File::ORIGINAL, $media->original()?->handle());
    }

    #[Test]
    public function a_file_by_handle_is_a_single_file_or_a_file_of_a_group(): void
    {
        $media = $this->mediaWithFiles('video', Visibility::Public, [
            ['original', null, 'original'],
            ['thumbnails', 2, 'thumbnail'],
            ['thumbnails', 0, 'thumbnail'],
            ['thumbnails', 1, 'thumbnail'],
        ]);

        $this->assertSame(0, $media->file('thumbnails')?->index());
        $this->assertSame(2, $media->file('thumbnails', 2)?->index());
        $this->assertSame('thumbnails', $media->file('thumbnails', 1)?->handle());
        $this->assertNull($media->file('thumbnails', 3));
        $this->assertNull($media->file('h264'));
    }

    #[Test]
    public function files_are_ordered_by_handle_and_index(): void
    {
        $media = $this->mediaWithFiles('video', Visibility::Public, [
            ['thumbnails', 1, 'thumbnail'],
            ['original', null, 'original'],
            ['thumbnails', 0, 'thumbnail'],
            ['h264', null, 'main'],
        ]);

        $this->assertSame(
            ['h264:', 'original:', 'thumbnails:0', 'thumbnails:1'],
            $media->files()->map(fn (File $file) => $file->handle().':'.$file->index())->all(),
        );
        $this->assertSame([0, 1], $media->files('thumbnails')->map(fn (File $file) => $file->index())->all());
        $this->assertTrue($media->files('missing')->isEmpty());
    }

    #[Test]
    public function the_thumbnail_is_the_poster_first(): void
    {
        $media = $this->mediaWithFiles('video', Visibility::Public, [
            ['original', null, 'original'],
            ['frames', 0, 'thumbnail'],
            ['still', null, 'poster'],
        ]);

        $this->assertSame('still', $media->thumbnail()?->handle());
    }

    #[Test]
    public function the_thumbnail_is_the_first_thumbnail_without_a_poster(): void
    {
        $withThumbnails = $this->mediaWithFiles('video', Visibility::Public, [
            ['original', null, 'original'],
            ['thumbnails', 1, 'thumbnail'],
            ['thumbnails', 0, 'thumbnail'],
        ]);
        $withoutThumbnail = $this->mediaWithFiles('audio', Visibility::Public, [['original', null, 'original'], ['mp3', null, 'main']]);

        $this->assertSame(0, $withThumbnails->thumbnail()?->index());
        $this->assertNull($withoutThumbnail->thumbnail());
    }

    #[Test]
    public function metadata_reads_a_key_with_a_default(): void
    {
        $media = $this->mediaWithFiles('video', Visibility::Public, [['original', null, 'original']], [
            'duration' => 12.5,
            'exif' => ['make' => 'Camera'],
        ]);

        $this->assertSame(12.5, $media->metadata('duration'));
        $this->assertSame('Camera', $media->metadata('exif.make'));
        $this->assertSame('none', $media->metadata('width', 'none'));
        $this->assertSame(['duration' => 12.5, 'exif' => ['make' => 'Camera']], $media->metadata());
    }

    #[Test]
    public function files_follow_the_visibility_of_their_media(): void
    {
        $public = $this->mediaWithFiles('image', Visibility::Public, [['original', null, 'original']]);
        $private = $this->mediaWithFiles('image', Visibility::Private, [['original', null, 'original']]);

        $this->assertSame(Visibility::Public, $public->original()?->visibility());
        $this->assertSame(Visibility::Private, $private->original()?->visibility());
        $this->assertSame(Visibility::Private, $private->visibility());
    }
}
