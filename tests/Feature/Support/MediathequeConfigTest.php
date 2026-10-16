<?php

namespace Folklore\Mediatheque\Tests\Feature\Support;

use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class MediathequeConfigTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set before the provider merges it: only overrides, as a published config would be.
        $app['config']->set('mediatheque', [
            'ingest' => ['visibility' => 'private'],
            'types' => [
                'video' => ['max_size' => '20G'],
                'model' => ['mimes' => ['model/*'], 'max_size' => 1000],
                'captions' => false,
            ],
        ]);
    }

    private function reader(): MediathequeConfig
    {
        return app(MediathequeConfig::class);
    }

    #[Test]
    public function the_application_config_is_merged_with_the_defaults(): void
    {
        $this->assertSame('mediatheque_', config('mediatheque.table_prefix'));
        $this->assertSame(Visibility::Private, $this->reader()->defaultVisibility());
        $this->assertSame(['video/*'], $this->reader()->type('video')->mimes);
        $this->assertSame(20 * 1024 ** 3, $this->reader()->type('video')->maxSize);
        $this->assertSame(['video', 'audio', 'image', 'document', 'font', 'model'], array_keys($this->reader()->types()));
        $this->assertSame(['h264', 'poster', 'thumbnails'], array_keys($this->reader()->pipeline('video')->renditions));
    }

    #[Test]
    public function the_default_config_is_valid(): void
    {
        $this->reader()->validate();

        $this->assertSame('public', $this->reader()->defaultSource());
        $this->assertSame('med', $this->reader()->mediaPrefix());
        $this->assertSame(3600, $this->reader()->temporaryTtl());
    }

    #[Test]
    public function the_type_comes_from_the_first_type_that_accepts_the_mime(): void
    {
        $this->assertSame('video', $this->reader()->detectType('video/quicktime')?->name);
        $this->assertSame('image', $this->reader()->detectType('IMAGE/PNG')?->name);
        $this->assertSame('font', $this->reader()->detectType('application/vnd.ms-opentype')?->name);
        $this->assertSame('model', $this->reader()->detectType('model/gltf-binary')?->name);
        $this->assertNull($this->reader()->detectType('text/vtt'), 'A removed type detects nothing.');
        $this->assertNull($this->reader()->detectType('text/html'));
        $this->assertNull($this->reader()->detectType('application/x-httpd-php'));
    }

    #[Test]
    public function a_type_runs_the_pipeline_of_its_name_unless_it_sets_one(): void
    {
        $this->assertSame('video', $this->reader()->pipelineOfType('video')?->name);
        $this->assertNull($this->reader()->pipelineOfType('image'));
        $this->assertNull($this->reader()->pipelineOfType('model'));
        $this->assertSame('h264', $this->reader()->mainRenditionOfType('video'));
        $this->assertSame('mp3', $this->reader()->mainRenditionOfType('audio'));
        $this->assertNull($this->reader()->mainRenditionOfType('image'));
        $this->assertNull($this->reader()->mainRenditionOfType('unknown'));
    }

    #[Test]
    public function sizes_are_read_in_bytes(): void
    {
        $this->assertSame(1000, $this->reader()->maxSize($this->reader()->type('model')));
        $this->assertSame(50 * 1024 ** 2, $this->reader()->maxSize($this->reader()->type('image')));
        $this->assertSame(2 * 1024 ** 3, $this->reader()->maxSize());
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'source that is not a disk' => ['mediatheque.source', 'missing', 'mediatheque.source'];
        yield 'visibility' => ['mediatheque.ingest.visibility', 'hidden', 'mediatheque.ingest.visibility'];
        yield 'size' => ['mediatheque.ingest.max_size', '2 gigs', 'mediatheque.ingest.max_size'];
        yield 'mimes' => ['mediatheque.types.video.mimes', 'video/*', 'mediatheque.types.video.mimes'];
        yield 'mime pattern' => ['mediatheque.types.video.mimes', ['video'], 'mediatheque.types.video.mimes'];
        yield 'type pipeline' => ['mediatheque.types.video.pipeline', 'missing', 'mediatheque.types.video.pipeline'];
        yield 'rendition name' => ['mediatheque.pipelines.video.renditions', ['1080p' => ['op' => 'video.transcode']], 'mediatheque.pipelines.video'];
        yield 'when.kind' => ['mediatheque.pipelines.video.renditions', ['h264' => ['op' => 'video.transcode', 'when' => ['kind' => 'captions']]], 'mediatheque.pipelines.video'];
        yield 'pipeline source' => ['mediatheque.pipelines.video.source', 'missing', 'mediatheque.pipelines.video.source'];
        yield 'reserved pipeline name' => ['mediatheque.pipelines.probe', ['renditions' => []], 'mediatheque.pipelines.probe'];
        yield 'uid prefix' => ['mediatheque.ids.media_prefix', 'Med', 'mediatheque.ids.media_prefix'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function an_invalid_value_throws_with_its_key(string $key, mixed $value, string $reported): void
    {
        config([$key => $value]);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage($reported);

        $this->reader()->validate();
    }
}
