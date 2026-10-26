<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
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

    protected function storedMedia(Visibility $visibility, ?string $name): Media
    {
        $media = Mediatheque::fake()->ingest(UploadedFile::fake()->create('Spring clip.mp4', 10, 'video/mp4'), visibility: $visibility);
        $this->assertInstanceOf(FakeMedia::class, $media);
        $media->name = $name;

        return $media;
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

        $this->assertInstanceOf(FakeMedia::class, $media);
        $media->name = 'Episode 1';

        // The fake completed the main rendition: the media serves it, under
        // its name and the rendition's extension
        $this->assertSame('Episode 1.mp4', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: true)));
        $this->assertSame('h264.mp4', $this->downloadedAs((string) $media->file('h264')?->temporaryUrl($expiration, download: true)), 'A file keeps its own name.');
        $this->assertSame('Step 3.mp4', $this->downloadedAs((string) $media->temporaryUrl(null, $expiration, download: 'Step 3.mp4')));
        $this->assertNull($this->downloadedAs((string) $media->url()));
    }
}
