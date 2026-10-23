<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Support\MediaUsage;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Testing\FakeUrlGenerator;
use Folklore\Mediatheque\Testing\Models\FakeFile;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
use Folklore\Mediatheque\Tests\Contracts\MediaContractTests;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Factory;

class FakeModelsTest extends TestCase
{
    use MediaContractTests;

    protected function mediaWithFiles(string $type, Visibility $visibility, array $files, array $metadata = []): Media
    {
        $media = new FakeMedia(1, Uid::generate('med'), $type, 'Media', visibility: $visibility, metadata: $metadata);
        $urls = new FakeUrlGenerator(app(Factory::class), 3600);

        foreach ($files as [$handle, $index, $role]) {
            $media->attach(new FakeFile($media, $urls, $handle, 'public', $handle.'-'.$index.'.bin', index: $index, role: $role));
        }

        return $media;
    }

    protected function useMedia(Media $media, string $handle): Media
    {
        $this->assertInstanceOf(FakeMedia::class, $media);
        $media->usages[] = new MediaUsage('post', 1, $handle, 0);

        return $media;
    }
}
