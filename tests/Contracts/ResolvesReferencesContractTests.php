<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\References\MediaReference;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mediatheque::resolve() and resolveMany() (docs/decisions/0003).
 */
trait ResolvesReferencesContractTests
{
    abstract protected function mediatheque(): Mediatheque;

    abstract protected function createMedia(): Media;

    /**
     * The internal id of a media, which 1.x references use.
     */
    abstract protected function internalId(Media $media): int;

    #[Test]
    public function it_resolves_a_media_by_reference_or_uid(): void
    {
        $media = $this->createMedia();
        $this->createMedia();

        $this->assertSame($media->uid(), $this->mediatheque()->resolve('media://'.$media->uid())?->uid());
        $this->assertSame($media->uid(), $this->mediatheque()->resolve(MediaReference::forMedia($media))?->uid());
        $this->assertSame($media->uid(), $this->mediatheque()->resolve($media->uid())?->uid());
    }

    #[Test]
    public function it_resolves_a_1x_reference_by_internal_id(): void
    {
        $this->createMedia();
        $media = $this->createMedia();

        $this->assertSame($media->uid(), $this->mediatheque()->resolve('medias://'.$this->internalId($media))?->uid());
    }

    #[Test]
    public function what_does_not_resolve_is_null(): void
    {
        $this->createMedia();

        $this->assertNull($this->mediatheque()->resolve('media://med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P'));
        $this->assertNull($this->mediatheque()->resolve('medias://999999'));
        $this->assertNull($this->mediatheque()->resolve('not a reference'));
        $this->assertNull($this->mediatheque()->resolve('1'));
    }

    #[Test]
    public function it_resolves_many_references_keyed_as_given(): void
    {
        $first = $this->createMedia();
        $second = $this->createMedia();

        $resolved = $this->mediatheque()->resolveMany([
            'media://'.$first->uid(),
            'medias://'.$this->internalId($second),
            MediaReference::forMedia($second),
            'media://med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P',
        ]);

        $this->assertSame([
            'media://'.$first->uid() => $first->uid(),
            'medias://'.$this->internalId($second) => $second->uid(),
            'media://'.$second->uid() => $second->uid(),
        ], $resolved->map(fn (Media $media) => $media->uid())->all());
    }
}
