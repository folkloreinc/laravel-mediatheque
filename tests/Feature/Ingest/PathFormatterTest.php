<?php

namespace Folklore\Mediatheque\Tests\Feature\Ingest;

use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Exceptions\InvalidPath;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PathFormatterTest extends TestCase
{
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
    private function media(array $attributes = []): Media
    {
        return (new Media)->forceFill(['uid' => 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', 'type' => 'video', ...$attributes]);
    }

    #[Test]
    public function the_default_formats_give_each_media_and_run_its_own_paths(): void
    {
        $media = $this->media();
        $run = (new PipelineRun)->forceFill(['uid' => 'run_01JAB3QX5Z8Y7W6V5T4S3R2Q1Q', 'path_prefix' => 'video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_01JAB3QX5Z8Y7W6V5T4S3R2Q1Q']);
        $run->setRelation('pipelinable', $media);

        $this->assertSame('video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original.mov', $this->formatter()->original($media, 'mov'));
        $this->assertSame('video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_01JAB3QX5Z8Y7W6V5T4S3R2Q1Q', $this->formatter()->runPrefix($media, $run->key()));
        $this->assertSame('video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_01JAB3QX5Z8Y7W6V5T4S3R2Q1Q/h264.mp4', $this->formatter()->runFile($run, 'h264', null, 'mp4'));
        $this->assertSame('video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_01JAB3QX5Z8Y7W6V5T4S3R2Q1Q/thumbnails-2.jpg', $this->formatter()->runFile($run, 'thumbnails', 2, 'jpg'));
    }

    #[Test]
    public function a_replaced_original_has_a_version_in_its_file_name(): void
    {
        $media = $this->media();

        $this->assertSame('video/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original-0a1b2c3d4e5f6789.mov', $this->formatter()->original($media, 'mov', '0a1b2c3d4e5f6789'));

        config(['mediatheque.file_path_format' => '{media.uid}/{handle}{index}']);
        $this->assertSame('med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original-0a1b2c3d', $this->formatter()->original($media, 'mov', '0a1b2c3d'));

        $this->expectException(InvalidPath::class);
        $this->formatter()->original($media, 'mov', '../x');
    }

    #[Test]
    public function application_attributes_and_dates_are_placeholders(): void
    {
        Date::setTestNow('2026-10-16 12:00:00');
        config([
            'mediatheque.file_path_format' => 'spaces/{media.space_id}/{date(Y/m)}/{media.uid}/{handle}{index}.{extension}',
            'mediatheque.run_path_format' => 'spaces/{media.space_id}/{media.uid}/{run}',
        ]);
        $media = $this->media(['space_id' => 42]);

        $this->assertSame('spaces/42/2026/10/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/original.mp4', $this->formatter()->original($media, 'mp4'));
        $this->assertSame('spaces/42/med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P/run_1', $this->formatter()->runPrefix($media, 'run_1'));

        $media->forceFill(['created_at' => '2025-01-31 10:00:00']);
        $this->assertStringStartsWith('spaces/42/2025/01/', $this->formatter()->original($media, 'mp4'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unsafeAttributes(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['space_id' => '']];
        yield 'a slash' => [['space_id' => 'a/b']];
        yield 'a parent directory' => [['space_id' => '..']];
        yield 'a backslash' => [['space_id' => 'a\\b']];
        yield 'a control character' => [['space_id' => "a\nb"]];
        yield 'not a scalar' => [['space_id' => ['a']]];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[Test]
    #[DataProvider('unsafeAttributes')]
    public function an_attribute_must_be_set_and_stay_in_its_segment(array $attributes): void
    {
        config(['mediatheque.file_path_format' => '{media.space_id}/{media.uid}/{handle}{index}.{extension}']);
        $media = (new Media)->forceFill(['uid' => Uid::generate('med'), 'type' => 'video']);
        foreach ($attributes as $key => $value) {
            $media->setRawAttributes([...$media->getAttributes(), $key => $value]);
        }

        $this->expectException(InvalidPath::class);

        $this->formatter()->original($media, 'mp4');
    }

    #[Test]
    public function an_extension_cannot_leave_its_segment(): void
    {
        $this->expectException(InvalidPath::class);

        $this->formatter()->original($this->media(), '../php');
    }

    #[Test]
    public function an_unknown_placeholder_throws(): void
    {
        config(['mediatheque.file_path_format' => '{media.uid}/{handle}{index}.{ext}']);

        $this->expectException(InvalidPath::class);
        $this->expectExceptionMessage('{ext}');

        $this->formatter()->original($this->media(), 'mp4');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFormats(): iterable
    {
        yield 'file without the uid' => ['file_path_format', '{type}/{handle}{index}.{extension}'];
        yield 'file without the index' => ['file_path_format', '{media.uid}/{handle}.{extension}'];
        yield 'run without the run' => ['run_path_format', '{type}/{media.uid}'];
    }

    #[Test]
    #[DataProvider('invalidFormats')]
    public function the_config_reader_refuses_formats_that_could_overwrite_files(string $key, string $format): void
    {
        config(['mediatheque.'.$key => $format]);

        $this->expectException(InvalidConfig::class);
        $this->expectExceptionMessage('mediatheque.'.$key);

        $this->formatter()->runPrefix($this->media(), 'run_1');
        $this->formatter()->original($this->media(), 'mp4');
    }
}
