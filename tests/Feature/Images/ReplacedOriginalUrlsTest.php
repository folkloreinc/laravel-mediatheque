<?php

namespace Folklore\Mediatheque\Tests\Feature\Images;

use Folklore\Image\Facades\Image;
use Folklore\Image\ImageServiceProvider;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Images\MediaResolver;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\ServiceProvider;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * A replaced original changes the version of its `laravel-image` URLs, its
 * checksum's (docs/decisions/0013, 0019), so caches never serve the
 * previous image.
 */
class ReplacedOriginalUrlsTest extends DatabaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImageServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    private function upload(string $variant = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents((string) $path, file_get_contents(self::fixturePath('media/image.jpg')).$variant);

        return new UploadedFile((string) $path, 'photo.jpg', 'image/jpeg', null, true);
    }

    #[Test]
    public function the_checksum_and_the_image_urls_change(): void
    {
        Storage::fake('public');
        Bus::fake([ProbeMedia::class, DeleteStoredFiles::class]);
        $media = Mediatheque::ingest($this->upload());
        $before = Image::for($media)->width(400)->signed(false)->url();
        $version = app(MediaResolver::class)->resolve($media)->version;

        Mediatheque::replaceOriginal($media, $this->upload('retouched'));

        $checksum = (string) $media->original()?->checksum();
        $after = Image::for($media)->width(400)->signed(false)->url();
        $this->assertSame(substr((string) hash_file('sha256', self::fixturePath('media/image.jpg')), 0, 8), $version);
        $this->assertSame(substr($checksum, 0, 8), app(MediaResolver::class)->resolve($media)->version);
        $this->assertNotSame($before, $after);
        $this->assertStringContainsString($version, $before);
        $this->assertStringNotContainsString($version, $after);
        $this->assertStringContainsString(substr($checksum, 0, 8), $after);
    }
}
