<?php

namespace Folklore\Mediatheque\Tests\Feature\Ingest;

use Folklore\Mediatheque\Exceptions\InvalidPath;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Feature\Ingest\Fixtures\PostMedia;
use Folklore\Mediatheque\Tests\Support\Post;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

/**
 * `{media.<attribute>}` reads an accessor of the media's model when no
 * column has that name (#64).
 */
class PathFormatterAccessorsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);

        parent::tearDown();
    }

    private function formatter(): PathFormatter
    {
        return app(PathFormatter::class);
    }

    /**
     * A media that is not saved yet, as ingest has it when it builds the
     * original's path.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function media(array $attributes = []): PostMedia
    {
        return (new PostMedia)->forceFill(['uid' => 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', 'type' => 'video', ...$attributes]);
    }

    #[Test]
    public function an_accessor_that_reads_a_relation_gives_a_placeholder_its_value(): void
    {
        config([
            'mediatheque.file_path_format' => '{media.post_slug}/{media.uid}/{handle}{index}.{extension}',
            'mediatheque.run_path_format' => '{media.post_slug}/{media.uid}/{run}',
        ]);
        $post = Post::query()->create(['title' => 'Spring Issue']);

        // Ingest sets the application's attributes, here the foreign key,
        // before it builds the path
        $media = $this->media(['post_id' => $post->id]);

        $this->assertSame('spring-issue/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original.mp4', $this->formatter()->original($media, 'mp4'));
        $this->assertSame('spring-issue/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_1', $this->formatter()->runPrefix($media, 'run_1'));
    }

    #[Test]
    public function an_accessor_without_a_value_throws_as_a_missing_attribute_does(): void
    {
        config(['mediatheque.file_path_format' => '{media.post_slug}/{media.uid}/{handle}{index}.{extension}']);

        $this->expectException(InvalidPath::class);
        $this->expectExceptionMessage('{media.post_slug}');

        $this->formatter()->original($this->media(), 'mp4');
    }

    #[Test]
    public function a_column_gives_its_raw_value_even_with_an_accessor_of_its_name(): void
    {
        config(['mediatheque.file_path_format' => '{media.space_id}/{media.uid}/{handle}{index}.{extension}']);

        $this->assertSame('42/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original.mp4', $this->formatter()->original($this->media(['space_id' => 42]), 'mp4'));
    }

    #[Test]
    public function a_saved_media_that_prevents_accessing_missing_attributes_reads_its_accessors(): void
    {
        config(['mediatheque.run_path_format' => '{media.short_uid}/{media.uid}/{run}']);
        $media = $this->media();
        $media->save();
        $media = PostMedia::query()->whereKey($media->getKey())->firstOrFail();
        Model::preventAccessingMissingAttributes();

        $this->assertSame('3R2Q1P/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_1', $this->formatter()->runPrefix($media, 'run_1'));
    }

    #[Test]
    public function a_saved_media_that_prevents_accessing_missing_attributes_throws_invalid_path_for_a_missing_one(): void
    {
        config(['mediatheque.run_path_format' => '{media.tenant_uid}/{media.uid}/{run}']);
        $media = $this->media();
        $media->save();
        $media = PostMedia::query()->whereKey($media->getKey())->firstOrFail();
        Model::preventAccessingMissingAttributes();

        $this->expectException(InvalidPath::class);
        $this->expectExceptionMessage('{media.tenant_uid}');

        $this->formatter()->runPrefix($media, 'run_1');
    }
}
