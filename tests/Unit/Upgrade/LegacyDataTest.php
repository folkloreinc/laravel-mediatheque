<?php

namespace Folklore\Mediatheque\Tests\Unit\Upgrade;

use Folklore\Mediatheque\Tests\Support\V1Database;
use Folklore\Mediatheque\Upgrade\LegacyHandle;
use Folklore\Mediatheque\Upgrade\LegacyJobs;
use Folklore\Mediatheque\Upgrade\LegacyMetadata;
use Folklore\Mediatheque\Upgrade\LegacyPipelineDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shapes 1.x stored: handles, EAV metadata, serialized pipelines and job
 * definitions.
 */
class LegacyDataTest extends TestCase
{
    #[Test]
    public function grouped_handles_are_split_into_handle_and_index(): void
    {
        $thumbnail = LegacyHandle::parse('thumbnails.3');
        $this->assertSame(['thumbnails', 3], [$thumbnail->handle, $thumbnail->index]);
        $this->assertSame('thumbnail', $thumbnail->role());
        $this->assertTrue($thumbnail->isValid());
        $this->assertSame('thumbnails.3', LegacyHandle::format('thumbnails', 3));
        $this->assertSame('h264', LegacyHandle::format('h264', null));

        $original = LegacyHandle::parse('original');
        $this->assertSame(['original', null, 'original'], [$original->handle, $original->index, $original->role()]);
        $this->assertTrue($original->isValid());

        $this->assertSame('main', LegacyHandle::parse('h264')->role());
        $this->assertFalse(LegacyHandle::parse('1080p')->isValid());
        $this->assertFalse(LegacyHandle::parse('media_convert.webm')->isValid());
        $this->assertNull(LegacyHandle::parse('media_convert.webm')->index);
    }

    #[Test]
    public function eav_rows_become_metadata_with_the_canonical_keys(): void
    {
        $rows = [
            (object) ['name' => 'width', 'type' => 'integer', 'value_integer' => '1920'],
            (object) ['name' => 'duration', 'type' => 'float', 'value_float' => '12.5000'],
            (object) ['name' => 'font_family_name', 'type' => 'string', 'value_string' => 'Generic Sans'],
            (object) ['name' => 'colors', 'type' => 'json', 'value_json' => '["#000000"]'],
            (object) ['name' => 'animated', 'type' => 'boolean', 'value_boolean' => 1],
            (object) ['name' => 'pageCount', 'type' => 'integer', 'value_integer' => 3],
            (object) ['name' => 'audio_tracks_count', 'type' => 'integer', 'value_integer' => null],
        ];

        $this->assertSame([
            'animated' => true,
            'duration' => 12.5,
            'font_names' => ['Generic Sans'],
            'pages_count' => 3,
            'palette' => ['#000000'],
            'width' => 1920,
        ], LegacyMetadata::toArray($rows));
    }

    #[Test]
    public function a_serialized_pipeline_is_read_without_creating_objects(): void
    {
        $definition = LegacyPipelineDefinition::parse(V1Database::serializedPipeline('video', [
            'h264' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264',
            'bumper' => ['job' => 'App\\Jobs\\Bumper', 'duration' => 2],
        ]));

        $this->assertNotNull($definition);
        $this->assertSame('video', $definition->name);
        $this->assertSame('original', $definition->fromFile);
        $this->assertSame(['h264', 'bumper'], array_keys($definition->jobs));
        $this->assertSame(['App\\Jobs\\Bumper'], $definition->jobsWithoutOperation());
    }

    #[Test]
    public function an_unreadable_definition_is_null(): void
    {
        $serialized = V1Database::serializedPipeline('video', ['h264' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264']);

        $this->assertNull(LegacyPipelineDefinition::parse(substr($serialized, 0, 40)), 'Truncated');
        $this->assertNull(LegacyPipelineDefinition::parse(null));
        $this->assertNull(LegacyPipelineDefinition::parse('{"jobs":[]}'));
        $this->assertNull(LegacyPipelineDefinition::parse(serialize(['jobs' => []])));
        // An object of a class that exists is not created either
        $this->assertNull(LegacyPipelineDefinition::parse(serialize(new \ArrayObject)));
    }

    #[Test]
    public function the_package_jobs_become_renditions(): void
    {
        $jobs = 'Folklore\\Mediatheque\\Jobs\\';

        $this->assertSame(['op' => 'video.transcode', 'codec' => 'h264'], LegacyJobs::rendition($jobs.'Video\\H264'));
        $this->assertSame(['op' => 'video.transcode', 'codec' => 'vp9', 'queue' => 'media'], LegacyJobs::rendition(['job' => $jobs.'Video\\WebM', 'queue' => 'media']));
        $this->assertSame(['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail', 'from' => 'h264'], LegacyJobs::rendition(['job' => $jobs.'Video\\Thumbnails', 'count' => 5, 'from_file' => 'h264']));
        $this->assertSame(['op' => 'audio.waveform', 'width' => 1200, 'height' => 400, 'color' => '#000000', 'role' => 'thumbnail'], LegacyJobs::rendition(['job' => $jobs.'Audio\\Thumbnails', 'width' => 1200, 'height' => 400, 'color' => '000000']));
        $this->assertNull(LegacyJobs::rendition($jobs.'Font\\WebFonts'));
        $this->assertNull(LegacyJobs::rendition('App\\Jobs\\Bumper'));

        $this->assertTrue(LegacyJobs::runsOnFfmpeg(['op' => 'video.transcode', 'codec' => 'vp9']));
        $this->assertFalse(LegacyJobs::runsOnFfmpeg(['op' => 'video.transcode', 'codec' => 'hevc']));
        $this->assertTrue(LegacyJobs::runsOnFfmpeg(['op' => 'document.thumbnails']));
        $this->assertSame(['op' => 'document.thumbnails', 'role' => 'thumbnail'], LegacyJobs::rendition(['job' => $jobs.'Document\\Thumbnails', 'count' => 'all', 'resolution' => 150, 'quality' => 100, 'format' => 'jpeg']));
        $this->assertSame(['op' => 'document.thumbnails', 'count' => 1, 'resolution' => 72, 'format' => 'png', 'quality' => 80, 'role' => 'thumbnail'], LegacyJobs::rendition(['job' => $jobs.'Document\\Thumbnails', 'count' => 1, 'resolution' => 72, 'quality' => 80, 'format' => 'png']));

        $this->assertSame(
            ['duration' => 2, 'queue' => null, 'op' => LegacyJobs::LEGACY_OPERATION, 'job' => 'App\\Jobs\\Bumper', 'from' => null],
            LegacyJobs::snapshot(['job' => 'App\\Jobs\\Bumper', 'duration' => 2, 'queue' => true]),
        );
    }
}
