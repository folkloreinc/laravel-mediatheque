<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Tests\Contracts\UrlGeneratorContractTests;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

class FakeUrlGeneratorTest extends TestCase
{
    use UrlGeneratorContractTests;

    protected function urlGenerator(): UrlGenerator
    {
        return Mediatheque::fake()->urls();
    }

    protected function storedFile(Visibility $visibility): File
    {
        $media = Mediatheque::fake()->ingest(UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg'), visibility: $visibility);
        $file = $media->original();
        $this->assertNotNull($file);

        return $file;
    }

    protected function downloadedAs(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['download']) && is_string($query['download']) ? $query['download'] : null;
    }

    protected function downloadsFiles(): bool
    {
        return true;
    }

    protected function temporaryTtl(): int
    {
        return 3600;
    }

    #[Test]
    public function the_fake_media_gives_a_download_url_of_the_file_it_plays(): void
    {
        $media = Mediatheque::fake()->ingest(UploadedFile::fake()->create('Spring clip.mp4', 10, 'video/mp4'), visibility: Visibility::Private);
        $expiration = Date::now()->addMinutes(5);

        // The fake completed the main rendition: the media plays it, under
        // the name its transcoder wrote
        $this->assertSame('h264.mp4', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: true)));
        $this->assertSame('Spring clip.mp4', $this->downloadedAs((string) $media->temporaryUrl('original', $expiration, download: true)));
        $this->assertSame('Step 3.mp4', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: 'Step 3.mp4')));
        $this->assertNull($this->downloadedAs((string) $media->url()));
    }
}
