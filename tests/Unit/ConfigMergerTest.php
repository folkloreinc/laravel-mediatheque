<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Support\ConfigMerger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigMergerTest extends TestCase
{
    /** @var array<string, mixed> */
    private const array DEFAULTS = [
        'source' => 'public',
        'ingest' => ['visibility' => 'public', 'max_size' => '2G'],
        'types' => [
            'video' => ['mimes' => ['video/*', 'image/gif'], 'max_size' => '10G'],
            'image' => ['mimes' => ['image/*'], 'pipeline' => null],
        ],
        'pipelines' => [
            'video' => [
                'transcoder' => 'ffmpeg',
                'source' => null,
                'renditions' => [
                    'h264' => ['op' => 'video.transcode', 'codec' => 'h264'],
                    'poster' => ['op' => 'video.poster', 'role' => 'poster'],
                ],
            ],
        ],
    ];

    #[Test]
    public function a_config_only_needs_its_overrides(): void
    {
        $merged = ConfigMerger::merge(self::DEFAULTS, [
            'ingest' => ['visibility' => 'private'],
            'types' => ['video' => ['max_size' => '20G']],
        ]);

        $this->assertSame(['visibility' => 'private', 'max_size' => '2G'], $merged['ingest']);
        $this->assertSame(['mimes' => ['video/*', 'image/gif'], 'max_size' => '20G'], data_get($merged, 'types.video'));
        $this->assertSame(self::DEFAULTS['pipelines'], $merged['pipelines']);
    }

    #[Test]
    public function lists_replace_the_default_list(): void
    {
        $merged = ConfigMerger::merge(self::DEFAULTS, ['types' => ['video' => ['mimes' => ['video/mp4']]]]);

        $this->assertSame(['video/mp4'], data_get($merged, 'types.video.mimes'));
    }

    #[Test]
    public function the_renditions_of_a_pipeline_replace_the_defaults(): void
    {
        $merged = ConfigMerger::merge(self::DEFAULTS, ['pipelines' => ['video' => [
            'transcoder' => 'eclair',
            'renditions' => ['hls' => ['op' => 'video.hls']],
        ]]]);

        $this->assertSame([
            'transcoder' => 'eclair',
            'source' => null,
            'renditions' => ['hls' => ['op' => 'video.hls']],
        ], data_get($merged, 'pipelines.video'));
    }

    #[Test]
    public function new_entries_are_added_after_the_defaults_and_false_is_kept(): void
    {
        $merged = ConfigMerger::merge(self::DEFAULTS, ['types' => [
            'image' => false,
            'model' => ['mimes' => ['model/*']],
        ]]);

        $this->assertSame(['video', 'image', 'model'], array_keys((array) $merged['types']));
        $this->assertFalse(data_get($merged, 'types.image'));
    }

    #[Test]
    public function merging_twice_gives_the_same_config(): void
    {
        $overrides = ['types' => ['video' => ['mimes' => ['video/mp4']]], 'pipelines' => ['video' => ['renditions' => []]]];
        $merged = ConfigMerger::merge(self::DEFAULTS, $overrides);

        $this->assertSame($merged, ConfigMerger::merge(self::DEFAULTS, $merged));
    }
}
