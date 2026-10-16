<?php

namespace Folklore\Mediatheque\Tests\Feature\Urls;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\UrlGeneratorContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Urls\DiskUrlGenerator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

class DiskUrlGeneratorTest extends DatabaseTestCase
{
    use CreatesMedia;
    use UrlGeneratorContractTests;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A local disk that serves its files through signed URLs
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/mediatheque-urls',
            'url' => 'https://cdn.example.com/media',
            'serve' => true,
        ]);
        $app['config']->set('mediatheque.urls.temporary_ttl', 900);
    }

    protected function urlGenerator(): UrlGenerator
    {
        return app(UrlGenerator::class);
    }

    protected function storedFile(Visibility $visibility, string $disk = 'media'): File
    {
        $media = $this->createMedia(['visibility' => $visibility]);

        return $this->createFile($media, 'original', ['source' => $disk, 'path' => 'video/'.$media->uid.'/original.mp4']);
    }

    protected function temporaryTtl(): int
    {
        return 900;
    }

    #[Test]
    public function the_package_binds_the_disk_url_generator(): void
    {
        $this->assertInstanceOf(DiskUrlGenerator::class, $this->urlGenerator());
    }

    #[Test]
    public function a_public_file_gets_its_disk_url(): void
    {
        $file = $this->storedFile(Visibility::Public);

        $this->assertSame('https://cdn.example.com/media/'.$file->path(), $file->url());
    }

    #[Test]
    public function a_private_file_gets_a_signed_url_that_expires(): void
    {
        Date::setTestNow('2026-10-16 12:00:00');
        $file = $this->storedFile(Visibility::Private);

        $url = (string) $file->url();

        $this->assertStringContainsString('expires='.Date::now()->addSeconds(900)->getTimestamp(), $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('cdn.example.com', $url);
    }

    #[Test]
    public function a_private_file_on_a_disk_that_cannot_sign_urls_has_no_url(): void
    {
        $file = $this->storedFile(Visibility::Private, 'public');

        $this->assertNull($file->url());
        $this->assertNull($file->temporaryUrl(Date::now()->addMinute()));
    }

    #[Test]
    public function a_file_without_a_source_has_no_url(): void
    {
        $media = $this->createMedia();
        $file = $this->createFile($media, 'h264', ['source' => null, 'path' => null, 'provider' => 'stream', 'provider_ref' => 'abc']);

        $this->assertTrue($file->isRemote());
        $this->assertNull($file->url());
    }

    #[Test]
    public function the_media_url_plays_the_main_rendition_or_the_original_until_it_exists(): void
    {
        $media = $this->createMedia(['type' => 'video']);
        $this->createFile($media, 'original', ['source' => 'media', 'path' => 'video/original.mov']);
        $this->createFile($media, 'poster', ['source' => 'media', 'path' => 'video/poster.jpg', 'role' => 'poster']);

        $this->assertSame('https://cdn.example.com/media/video/original.mov', $media->url());

        $this->createFile($media, 'h264', ['source' => 'media', 'path' => 'video/h264.mp4']);

        $this->assertSame('https://cdn.example.com/media/video/h264.mp4', $media->url());
        $this->assertSame('https://cdn.example.com/media/video/poster.jpg', $media->url('poster'));
        $this->assertNull($media->url('missing'));
    }
}
