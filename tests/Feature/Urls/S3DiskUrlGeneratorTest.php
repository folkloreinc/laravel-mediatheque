<?php

namespace Folklore\Mediatheque\Tests\Feature\Urls;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\UrlGeneratorContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

/**
 * URLs on an S3-compatible disk, such as DigitalOcean Spaces. URLs are signed
 * locally: the tests make no request.
 */
class S3DiskUrlGeneratorTest extends DatabaseTestCase
{
    use CreatesMedia;
    use UrlGeneratorContractTests;

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'key',
            'secret' => 'secret',
            'region' => 'nyc3',
            'bucket' => 'media',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        ]);
        $app['config']->set('mediatheque.urls.temporary_ttl', 900);
    }

    protected function urlGenerator(): UrlGenerator
    {
        return app(UrlGenerator::class);
    }

    protected function storedFile(Visibility $visibility): File
    {
        $media = $this->createMedia(['visibility' => $visibility]);

        return $this->createFile($media, 'original', ['source' => 'spaces', 'path' => 'video/'.$media->uid.'/original.mp4', 'name' => 'Spring clip.mp4']);
    }

    protected function downloadedAs(string $url): ?string
    {
        $disposition = $this->contentDisposition($url);

        return $disposition !== null && preg_match("/filename\\*=UTF-8''([^;]+)$/", $disposition, $matches) === 1
            ? rawurldecode($matches[1])
            : null;
    }

    protected function downloadsFiles(): bool
    {
        return true;
    }

    protected function temporaryTtl(): int
    {
        return 900;
    }

    /**
     * The `Content-Disposition` header a presigned URL asks for.
     */
    private function contentDisposition(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $disposition = $query['response-content-disposition'] ?? null;

        return is_string($disposition) ? $disposition : null;
    }

    #[Test]
    public function a_download_url_asks_for_an_attachment_with_its_name_in_utf8_and_in_ascii(): void
    {
        $file = $this->storedFile(Visibility::Private);

        $url = (string) $file->temporaryUrl(Date::now()->addMinutes(5), download: 'Clip d’été.mp4');

        $this->assertStringStartsWith('https://media.nyc3.digitaloceanspaces.com/video/', $url);
        $this->assertSame("attachment; filename=\"Clip d'ete.mp4\"; filename*=UTF-8''Clip%20d%E2%80%99%C3%A9t%C3%A9.mp4", $this->contentDisposition($url));
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    #[Test]
    public function the_media_gives_a_download_url_of_the_file_it_plays(): void
    {
        $media = $this->createMedia(['type' => 'video', 'visibility' => Visibility::Private]);
        $this->createFile($media, 'original', ['source' => 'spaces', 'path' => 'video/original.mov', 'name' => 'IMG_0042.MOV']);
        $expiration = Date::now()->addMinutes(5);

        $this->assertSame('IMG_0042.MOV', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: true)));

        $this->createFile($media, 'h264', ['source' => 'spaces', 'path' => 'video/h264.mp4', 'name' => 'h264.mp4']);

        $this->assertSame('Step 3.mp4', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: 'Step 3.mp4')));
        $this->assertStringContainsString('/video/h264.mp4', (string) $media->temporaryUrl(null, $expiration, download: 'Step 3.mp4'));
        $this->assertNull($this->downloadedAs((string) $media->url()), 'url() shows the file.');
    }
}
