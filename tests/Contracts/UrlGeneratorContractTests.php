<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rules of the UrlGenerator contract (docs/decisions/0007).
 */
trait UrlGeneratorContractTests
{
    abstract protected function urlGenerator(): UrlGenerator;

    /**
     * A file stored on a source, of a media with this visibility, with a
     * name.
     */
    abstract protected function storedFile(Visibility $visibility): File;

    /**
     * The name a temporary URL downloads its file under, or null when the
     * URL shows the file. Null for every URL when the implementation ignores
     * downloads, as on a source that can't ask for them.
     */
    abstract protected function downloadedAs(string $url): ?string;

    /**
     * Whether temporary URLs of the stored files can download them.
     */
    abstract protected function downloadsFiles(): bool;

    /**
     * The lifetime of the URL of a private file, in seconds.
     */
    abstract protected function temporaryTtl(): int;

    #[Test]
    public function a_public_file_gets_a_permanent_url(): void
    {
        $file = $this->storedFile(Visibility::Public);

        $url = $this->urlGenerator()->url($file);
        // Before travelling: S3 signs with the real clock
        $temporaryUrl = $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(5));
        Date::setTestNow(Date::now()->addDays(10));

        $this->assertNotNull($url);
        $this->assertSame($url, $this->urlGenerator()->url($file));
        $this->assertNotSame($url, $temporaryUrl);
    }

    #[Test]
    public function a_private_file_only_gets_temporary_urls_with_the_configured_lifetime(): void
    {
        Date::setTestNow('2026-10-16 12:00:00');
        $file = $this->storedFile(Visibility::Private);

        $this->assertSame(
            $this->urlGenerator()->temporaryUrl($file, Date::now()->addSeconds($this->temporaryTtl())),
            $this->urlGenerator()->url($file),
        );
    }

    #[Test]
    public function a_temporary_url_depends_on_its_expiration(): void
    {
        $file = $this->storedFile(Visibility::Private);

        $this->assertNotSame(
            $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(5)),
            $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(10)),
        );
    }

    #[Test]
    public function a_temporary_url_shows_its_file_unless_it_downloads_it(): void
    {
        $file = $this->storedFile(Visibility::Private);
        $expiration = Date::now()->addMinutes(5);

        $url = (string) $this->urlGenerator()->temporaryUrl($file, $expiration);

        $this->assertNull($this->downloadedAs($url));
        $this->assertSame($url, $this->urlGenerator()->temporaryUrl($file, $expiration, download: false));
    }

    #[Test]
    public function a_temporary_url_downloads_its_file_under_its_name(): void
    {
        $file = $this->storedFile(Visibility::Private);
        $expiration = Date::now()->addMinutes(5);
        $this->assertNotNull($file->name());

        $url = $this->urlGenerator()->temporaryUrl($file, $expiration, download: true);

        $this->assertNotNull($url);
        if ($this->downloadsFiles()) {
            $this->assertSame($file->name(), $this->downloadedAs($url));
        } else {
            $this->assertSame($this->urlGenerator()->temporaryUrl($file, $expiration), $url, 'An implementation that ignores downloads gives the same URL.');
        }
    }

    #[Test]
    public function a_temporary_url_downloads_its_file_under_a_name_it_is_given(): void
    {
        $file = $this->storedFile(Visibility::Public);
        $name = 'Clip d’été "final" 100%.mp4';

        $url = $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(5), download: $name);

        $this->assertNotNull($url);
        $this->assertSame($this->downloadsFiles() ? $name : null, $this->downloadedAs($url));
    }

    #[Test]
    public function a_name_to_download_under_is_a_file_name(): void
    {
        $file = $this->storedFile(Visibility::Private);

        foreach (['', ' ', '..', 'clips/clip.mp4', 'clip\\.mp4', "clip\r\nSet-Cookie: a=b.mp4"] as $name) {
            $this->assertThrows(
                fn () => $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(5), download: $name),
                InvalidArgumentException::class,
            );
        }
    }
}
