<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rules of the UrlGenerator contract (docs/decisions/0007).
 */
trait UrlGeneratorContractTests
{
    abstract protected function urlGenerator(): UrlGenerator;

    /**
     * A file stored on a source, of a media with this visibility.
     */
    abstract protected function storedFile(Visibility $visibility): File;

    /**
     * The lifetime of the URL of a private file, in seconds.
     */
    abstract protected function temporaryTtl(): int;

    #[Test]
    public function a_public_file_gets_a_permanent_url(): void
    {
        $file = $this->storedFile(Visibility::Public);

        $url = $this->urlGenerator()->url($file);
        Date::setTestNow(Date::now()->addDays(10));

        $this->assertNotNull($url);
        $this->assertSame($url, $this->urlGenerator()->url($file));
        $this->assertNotSame($url, $this->urlGenerator()->temporaryUrl($file, Date::now()->addMinutes(5)));
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
}
