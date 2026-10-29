<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Folklore\Mediatheque\Models\Media;

class MediaMethods
{
    public function show(Media $media, string $handle): array
    {
        return [
            $media->getFile('thumbnails.0'),
            $media->getFile('h264'),
            $media->getFile($handle),
            $media->hasFile('thumbnails.2'),
            $media->getOriginalFile(),
            $media->getUrl(),
            $media->getOriginalFile()->getUrl(),
            $media->getMetadatas()->get('width')->getValue(),
            $media->getMetadata('colors')->getValue(),
            $media->getMetadata('font_family_name')->getValue(),
            $media->getMetadata($handle)->getValue(),
            $media->hasFile($handle),
            $media->getFiles(),
            $media->runPipeline('video'),
        ];
    }
}

?>
-----
<?php

namespace Folklore\Mediatheque\Tests\Unit\Rector\Fixture;

use Folklore\Mediatheque\Models\Media;

class MediaMethods
{
    public function show(Media $media, string $handle): array
    {
        return [
            $media->file('thumbnails', 0),
            $media->file('h264'),
            $media->getFile($handle),
            $media->file('thumbnails', 2) !== null,
            $media->original(),
            $media->original()?->url(),
            $media->original()->url(),
            $media->metadata('width'),
            $media->metadata('palette'),
            $media->getMetadata('font_family_name')->getValue(),
            $media->getMetadata($handle)->getValue(),
            $media->hasFile($handle),
            $media->getFiles(),
            \Folklore\Mediatheque\Facades\Mediatheque::runPipeline($media, 'video'),
        ];
    }
}

?>
