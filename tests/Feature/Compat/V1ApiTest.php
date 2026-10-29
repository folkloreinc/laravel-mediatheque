<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactory;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\File as FileModel;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\LegacyPage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The 1.x API that the compatibility layer keeps working until 3.0, with a
 * deprecation for each use (docs/upgrade.md).
 */
class V1ApiTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Deprecation::flush();
        Storage::fake('public');
        Bus::fake([ProbeMedia::class]);
    }

    private function video(): Media
    {
        $media = $this->createMedia(['metadata' => ['width' => 1920, 'duration' => 12.5, 'font_names' => ['Generic Sans'], 'palette' => ['#000000']]]);
        $this->createFile($media);
        $this->createFile($media, 'h264');
        foreach (range(0, 2) as $index) {
            $this->createFile($media, 'thumbnails', ['index' => $index, 'role' => 'thumbnail']);
        }

        return $media->fresh() ?? $media;
    }

    private function assertDeprecated(string $method): void
    {
        $this->assertNotEmpty(array_filter(Deprecation::reported(), fn (string $message) => str_starts_with($message, $method)), sprintf('%s reports no deprecation.', $method));
    }

    #[Test]
    public function files_are_read_by_their_1x_handles(): void
    {
        $media = $this->video();

        $this->assertSame($media->file('thumbnails', 1)?->path(), $media->getFile('thumbnails.1')?->path());
        $this->assertSame($media->file('h264')?->path(), $media->getFile('h264')?->path());
        $this->assertNull($media->getFile('webm'));
        $this->assertTrue($media->hasFile('thumbnails.2'));
        $this->assertFalse($media->hasFile('thumbnails.3'));
        $this->assertSame(['h264', 'original', 'thumbnails.0', 'thumbnails.1', 'thumbnails.2'], $media->getFiles()->keys()->all());
        $this->assertSame($media->original()?->path(), $media->getOriginalFile()?->path());
        $this->assertCount(3, $media->getThumbnails());
        $this->assertCount(5, $media->files);
        $thumbnail = $media->file('thumbnails', 1);
        $this->assertInstanceOf(FileModel::class, $thumbnail);
        $this->assertSame('thumbnails.1', $thumbnail->getHandle());
        $this->assertSame($thumbnail->url(), $thumbnail->getUrl());

        foreach (['Media::getFile()', 'Media::hasFile()', 'Media::getFiles()', 'Media::getOriginalFile()', 'Media::getThumbnails()', 'Media::$files', 'File::getHandle()', 'File::getUrl()'] as $method) {
            $this->assertDeprecated($method);
        }
    }

    #[Test]
    public function metadata_is_read_as_values(): void
    {
        $media = $this->video();

        $this->assertSame(1920, $media->getMetadatas()->get('width')?->getValue());
        $duration = $media->getMetadata('duration');
        $this->assertNotNull($duration);
        $this->assertSame(12.5, $duration->getValue());
        $this->assertSame('duration', $duration->getName());
        $this->assertSame('Generic Sans', $media->getMetadata('font_family_name')?->getValue());
        $this->assertSame(['#000000'], $media->getMetadata('colors')?->getValue());
        $this->assertNull($media->getMetadata('height'));
        $this->assertDeprecated('Media::getMetadatas()');
        $this->assertDeprecated('Media::getMetadata()');
    }

    #[Test]
    public function get_url_is_the_original_s_url_as_in_1x(): void
    {
        $media = $this->video();

        $this->assertNotNull($media->getUrl());
        $this->assertSame($media->original()?->url(), $media->getUrl());
        $this->assertNotSame($media->url(), $media->getUrl(), 'url() is the main rendition\'s.');
        $this->assertDeprecated('Media::getUrl()');
    }

    #[Test]
    public function the_1x_files_relation_is_eager_loaded(): void
    {
        $this->video();

        $media = Media::query()->with('files')->firstOrFail();
        $this->assertTrue($media->relationLoaded('mediaFiles'));
        $this->assertCount(5, $media->files);

        $media = Media::query()->firstOrFail();
        $media->load('files');
        $this->assertTrue($media->relationLoaded('mediaFiles'));
        $media->unsetRelation('mediaFiles');
        $media->loadMissing('files');
        $this->assertTrue($media->relationLoaded('mediaFiles'));

        $page = LegacyPage::query()->create(['title' => 'Page']);
        $page->attachMedia($media, 'cover');
        $loaded = LegacyPage::query()->with('medias.files')->findOrFail($page->id)->media('cover');
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->relationLoaded('mediaFiles'));

        $this->assertDeprecated('Eager loading the media relation "files"');
    }

    #[Test]
    public function set_original_file_ingests_a_new_media_through_the_1x_type_factory(): void
    {
        $type = app(TypeFactory::class)->typeFromPath(self::fixturePath('media/image.jpg'));
        $this->assertNotNull($type);
        $this->assertSame('image', $type->name());

        $source = $this->video();
        $media = $type->newModel();
        $this->assertInstanceOf(Media::class, $media);
        // Attributes set before the call are kept, as 1.x saved them
        $media->derived_from_id = $source->id;
        $media->setOriginalFile(self::fixturePath('media/image.jpg'), ['name' => 'Photo', 'data' => ['credit' => 'Someone']]);
        $this->assertSame($source->id, $media->derived_from_id);
        $this->assertSame(['credit' => 'Someone'], $media->data);

        $this->assertTrue($media->exists);
        $this->assertSame('image', $media->type());
        $this->assertSame('Photo', $media->name());
        $this->assertNotNull($media->original());
        Storage::disk('public')->assertExists((string) $media->original()->path());
        $this->assertSame(2, Media::query()->count());
        $this->assertDeprecated('Media::setOriginalFile()');
        $this->assertDeprecated('The 1.x type factory');

        $this->assertTrue(app(TypeFactory::class)->hasType('video'));
        $this->assertFalse(app(TypeFactory::class)->hasType('webfont'));
        $this->assertNull(app(TypeFactory::class)->typeFromPath(self::fixturePath('media/missing.jpg')));
    }

    #[Test]
    public function set_original_file_replaces_the_original_of_a_saved_media(): void
    {
        $media = app(Mediatheque::class)->ingest(self::fixturePath('media/image.jpg'));
        $this->assertInstanceOf(Media::class, $media);
        $uid = $media->uid();
        $previous = $media->original()?->checksum();

        $media->setOriginalFile(self::fixturePath('media/animated.gif'));

        $media->refresh();
        $this->assertSame($uid, $media->uid());
        $this->assertNotSame($previous, $media->original()?->checksum());
        $this->assertSame(1, Media::query()->count());
    }

    #[Test]
    public function run_pipeline_runs_through_the_mediatheque(): void
    {
        Bus::fake([ProbeMedia::class, RunRendition::class]);
        $media = $this->video();

        $run = $media->runPipeline('video');

        $this->assertNotNull($run);
        $this->assertSame('video', $run->name());
        $this->assertSame($media->uid(), $run->media()->uid());
        $this->assertDeprecated('Media::runPipeline()');
    }

    #[Test]
    public function the_1x_trait_keeps_its_relation_and_gets_the_writes_by_handle(): void
    {
        $media = $this->video();
        $page = LegacyPage::query()->create(['title' => 'Page']);

        $page->medias()->sync([$media->getKey()]);
        $page->attachMedia($media, 'cover');

        $this->assertSame($media->getKey(), $page->media('cover')?->getKey());
        $this->assertCount(2, $page->medias()->get());
    }

    #[Test]
    public function medias_references_still_resolve(): void
    {
        $media = $this->video();

        $this->assertSame($media->uid(), app(Mediatheque::class)->resolve('medias://'.$media->id)?->uid());
        $this->assertTrue(MediaReference::parse('medias://'.$media->id)->isLegacy());
    }

    #[Test]
    public function files_of_the_contract_are_still_a_method(): void
    {
        $media = $this->video();

        $this->assertSame(['h264'], $media->files('h264')->map(fn (File $file) => $file->handle())->all());
    }
}
