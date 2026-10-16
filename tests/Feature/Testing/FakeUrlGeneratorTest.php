<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Tests\Contracts\UrlGeneratorContractTests;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Http\UploadedFile;

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

    protected function temporaryTtl(): int
    {
        return 3600;
    }
}
