<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Compat\LegacyConfig;
use Folklore\Mediatheque\Compat\V1Defaults;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Support\ConfigMerger;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 1.x `config/mediatheque.php`, read in the 2.0 shape.
 */
class LegacyConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function v1Config(): array
    {
        $config = require self::fixturePath('v1/config.php');
        $keyed = [];
        foreach (is_array($config) ? $config : [] as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

    /**
     * The 2.0 config the package reads from these overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function read(array $overrides): MediathequeConfig
    {
        $defaults = require __DIR__.'/../../../config/mediatheque.php';
        config(['mediatheque' => ConfigMerger::merge(is_array($defaults) ? $defaults : [], $overrides)]);

        return app(MediathequeConfig::class);
    }

    #[Test]
    public function the_1x_defaults_are_the_1x_config_file(): void
    {
        $this->assertEquals(self::v1Config(), V1Defaults::config());
    }

    #[Test]
    public function it_recognizes_the_1x_shape(): void
    {
        $this->assertTrue(LegacyConfig::isLegacy(self::v1Config()));
        $this->assertTrue(LegacyConfig::isLegacy(['types' => ['video' => ['mimes' => ['video/*' => '*']]]]));
        $this->assertTrue(LegacyConfig::isLegacy(['pipelines' => ['video' => ['jobs' => []]]]));
        $this->assertFalse(LegacyConfig::isLegacy(['types' => ['video' => ['mimes' => ['video/*']]], 'source' => 'public']));
        $this->assertFalse(LegacyConfig::isLegacy([]));
    }

    #[Test]
    public function the_1x_defaults_are_no_overrides_but_the_audio_pipeline(): void
    {
        $conversion = LegacyConfig::convert(self::v1Config(), ['public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => 'http://localhost/storage']]);

        // The 2.0 audio pipeline would also transcode audio to MP3, which 1.x
        // didn't: the 1.x waveform is kept. The video pipeline has a job 2.0
        // can't run (HEVC), and the document one is the 2.0 default: their
        // 2.0 defaults apply
        $this->assertSame([
            'table_prefix' => 'mediatheque_',
            'source' => 'public',
            'pipelines' => ['audio' => ['renditions' => ['thumbnails' => ['op' => 'audio.waveform', 'width' => 1200, 'height' => 400, 'color' => '#000000', 'role' => 'thumbnail']]]],
        ], $conversion->config);
        $this->assertContains('The 1.x pipeline "audio" is written as 1.x ran it: the 2.0 default would also run audio.transcode, which 1.x didn\'t.', $conversion->notices);
        $this->assertContains(LegacyConfig::DOCUMENT_PAGES, $conversion->notices);
        $this->assertSame([
            'pipelines.audio.jobs.thumbnails.zoom',
            'pipelines.audio.jobs.thumbnails.background_color',
        ], $conversion->unmappedOptions);
        // The `cloud` source of the defaults becomes a disk; `public` is one already
        $this->assertSame(['cloud'], array_keys($conversion->disks));
        $this->assertSame(storage_path('app/public'), Arr::get($conversion->disks, 'cloud.root'));
        $this->assertContains('2.0 has no upload routes: take files in your own routes and call Mediatheque::ingest() (docs/upgrade.md).', $conversion->notices);

        $this->read($conversion->config)->validate();
    }

    #[Test]
    public function the_overrides_of_a_1x_config_are_translated(): void
    {
        $config = self::v1Config();
        Arr::set($config, 'table_prefix', 'legacy_');
        Arr::set($config, 'source', 'cdn');
        Arr::set($config, 'sources.cdn', ['driver' => 'local', 'path' => storage_path('app/cdn'), 'url' => 'https://cdn.example.com']);
        Arr::set($config, 'sources.s3', ['driver' => 'filesystem', 'disk' => 'remote', 'path' => 'medias/', 'url' => 'https://assets.example.com']);
        Arr::set($config, 'file_path_format', '{type}/{id}.{extension}');
        Arr::set($config, 'types.video.mimes', ['video/mp4' => 'mp4', 'video/quicktime' => 'mov']);
        Arr::set($config, 'types.document.mimes', ['application/pdf' => 'pdf', 'application/octet-stream' => '*']);
        Arr::set($config, 'pipelines.video', [
            'queue' => 'media',
            'jobs' => [
                'h264' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264',
                'thumbnails' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\Thumbnails', 'count' => 3],
                'bumper' => 'App\\Jobs\\Bumper',
            ],
        ]);
        Arr::set($config, 'uploads.max_size', 1024);
        Arr::set($config, 'process_timeout', 1200);
        // `ffmpeg.binaries` has a dot
        Arr::set($config, 'services.ffmpeg', ['ffmpeg.binaries' => '/opt/ffmpeg/bin/ffmpeg', 'ffprobe.binaries' => '/usr/local/bin/ffprobe']);
        Arr::set($config, 'models', ['media' => Media::class]);

        $conversion = LegacyConfig::convert($config, ['remote' => ['driver' => 's3', 'bucket' => 'bucket', 'root' => 'app']]);

        $this->assertSame('legacy_', $conversion->config['table_prefix']);
        $this->assertSame('cdn', $conversion->config['source']);
        $this->assertSame(['driver' => 'local', 'root' => storage_path('app/cdn'), 'url' => 'https://cdn.example.com', 'visibility' => 'public', 'throw' => false], $conversion->disks['cdn']);
        $this->assertSame(['driver' => 's3', 'bucket' => 'bucket', 'root' => 'app/medias', 'url' => 'https://assets.example.com', 'visibility' => 'public'], $conversion->disks['s3']);
        $this->assertArrayNotHasKey('file_path_format', $conversion->config);
        $this->assertSame(['mimes' => ['video/mp4', 'video/quicktime']], Arr::get($conversion->config, 'types.video'));
        $this->assertSame(['mimes' => ['application/pdf']], Arr::get($conversion->config, 'types.document'));
        $this->assertSame([
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'queue' => 'media'],
            'thumbnails' => ['op' => 'video.thumbnails', 'count' => 3, 'role' => 'thumbnail', 'queue' => 'media'],
        ], Arr::get($conversion->config, 'pipelines.video.renditions'));
        $this->assertSame(['max_size' => 1024 * 1024], $conversion->config['ingest']);
        $this->assertSame(['timeout' => 1200], $conversion->config['jobs']);
        $this->assertSame(['ffmpeg' => '/opt/ffmpeg/bin/ffmpeg'], $conversion->config['binaries']);
        $this->assertSame(['media' => Media::class], $conversion->config['models']);

        $notices = implode("\n", $conversion->notices);
        $this->assertStringContainsString('file_path_format', $notices);
        $this->assertStringContainsString('App\\Jobs\\Bumper', $notices);
        $this->assertStringContainsString('application/octet-stream', $notices);

        foreach ($conversion->disks as $name => $disk) {
            config(['filesystems.disks.'.$name => $disk]);
        }
        $read = $this->read($conversion->config);
        $read->validate();
        $this->assertSame(['h264', 'thumbnails'], array_keys($read->pipeline('video')->renditions));
    }

    #[Test]
    public function a_type_whose_1x_pipeline_2_0_cant_run_has_none(): void
    {
        $config = self::v1Config();
        Arr::set($config, 'types.font.pipeline', 'fonts');
        Arr::set($config, 'pipelines.fonts', ['jobs' => ['webfonts' => 'Folklore\\Mediatheque\\Jobs\\Font\\WebFonts']]);

        $conversion = LegacyConfig::convert($config, []);

        $this->assertSame(['pipeline' => null], Arr::get($conversion->config, 'types.font'));
        $pipelines = $conversion->config['pipelines'] ?? [];
        $this->assertIsArray($pipelines);
        $this->assertArrayNotHasKey('fonts', $pipelines);
        $this->assertStringContainsString('Font\\WebFonts', implode("\n", $conversion->notices));
        $this->assertNull($this->read($conversion->config)->type('font')->pipeline);
    }

    #[Test]
    public function a_1x_source_that_the_disk_of_the_same_name_doesnt_store_is_a_conflict(): void
    {
        // The default `public` source of 1.x in 2017 and 2018
        $config = self::v1Config();
        Arr::set($config, 'sources.public', ['driver' => 'local', 'path' => public_path('files'), 'url' => '/files']);
        $laravel = ['public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => 'http://localhost/storage', 'visibility' => 'public']];

        $conversion = LegacyConfig::convert($config, $laravel);

        $this->assertCount(1, $conversion->conflicts);
        $this->assertStringContainsString('"public"', $conversion->conflicts[0]);
        $this->assertStringContainsString(public_path('files'), $conversion->conflicts[0]);
        $this->assertContains($conversion->conflicts[0], $conversion->notices);
        $this->assertArrayNotHasKey('public', $conversion->disks, 'The application\'s disk is left as it is.');
    }

    #[Test]
    public function a_1x_source_that_the_disk_of_the_same_name_stores_is_no_conflict(): void
    {
        $config = self::v1Config();
        Arr::set($config, 'sources.public', ['driver' => 'local', 'path' => storage_path('app/public'), 'url' => '/storage']);
        $laravel = ['public' => ['driver' => 'local', 'root' => storage_path('app/public').'/', 'url' => 'http://localhost/storage', 'visibility' => 'public']];

        $this->assertSame([], LegacyConfig::convert($config, $laravel)->conflicts);
    }

    /**
     * The video and audio pipelines of a 1.x site, with options of their
     * jobs, and named pipelines that resize their video.
     *
     * @return array<array-key, mixed>
     */
    public static function v1ConfigWithJobOptions(): array
    {
        $config = self::v1Config();
        Arr::set($config, 'pipelines.video.jobs', [
            'h264' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264', 'max_width' => 1920, 'max_height' => 1920],
            'webm' => [
                'job' => 'Folklore\\Mediatheque\\Jobs\\Video\\WebM',
                'max_width' => '1920',
                'max_height' => '1920',
                'quality' => 14,
                'audio_codec' => 'libvorbis',
                'parameters' => ['-deadline', 'good'],
            ],
            'thumbnails' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\Thumbnails', 'count' => 5, 'in_middle' => true],
        ]);
        Arr::set($config, 'pipelines.bumper_720', ['jobs' => ['h264' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264', 'resize' => ['720', '-2']]]]);
        Arr::set($config, 'pipelines.bumper_portrait', ['jobs' => ['h264' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264', 'resize' => ['-2', '1280'], 'max_height' => 1080]]]);
        Arr::set($config, 'pipelines.bumper_half', ['jobs' => ['h264' => ['job' => 'Folklore\\Mediatheque\\Jobs\\Video\\H264', 'resize' => ['iw/2', '-2'], 'quality' => '20']]]);
        Arr::set($config, 'pipelines.audio.jobs.thumbnails.width', '800');
        Arr::set($config, 'pipelines.audio.jobs.thumbnails.height', 200);
        Arr::set($config, 'pipelines.audio.jobs.thumbnails.color', 'FF0000');

        return $config;
    }

    #[Test]
    public function the_options_of_1x_jobs_become_the_options_of_their_renditions(): void
    {
        $conversion = LegacyConfig::convert(self::v1ConfigWithJobOptions(), []);

        $this->assertSame([
            'video' => ['renditions' => [
                'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 1920, 'max_height' => 1920],
                'webm' => ['op' => 'video.transcode', 'codec' => 'vp9', 'max_width' => 1920, 'max_height' => 1920, 'crf' => 14],
                'thumbnails' => ['op' => 'video.thumbnails', 'count' => 5, 'role' => 'thumbnail'],
            ]],
            'audio' => ['renditions' => [
                'thumbnails' => ['op' => 'audio.waveform', 'width' => 800, 'height' => 200, 'color' => '#FF0000', 'role' => 'thumbnail'],
            ]],
            // `resize` is a width, or a height, and the smaller of a size and `max_*`
            'bumper_720' => ['renditions' => ['h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 720]]],
            'bumper_portrait' => ['renditions' => ['h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_height' => 1080]]],
            // An expression is no size
            'bumper_half' => ['renditions' => ['h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'crf' => 20]]],
        ], $conversion->config['pipelines'] ?? null);

        $this->assertSame([
            'pipelines.video.jobs.webm.audio_codec',
            'pipelines.video.jobs.webm.parameters',
            'pipelines.video.jobs.thumbnails.in_middle',
            'pipelines.audio.jobs.thumbnails.zoom',
            'pipelines.audio.jobs.thumbnails.background_color',
            'pipelines.bumper_half.jobs.h264.resize',
        ], $conversion->unmappedOptions);

        $read = $this->read($conversion->config);
        $read->validate();
        $this->assertSame(['max_width' => 720], Arr::except($read->pipeline('bumper_720')->renditions['h264']->options, ['codec']));
    }

    #[Test]
    public function a_1x_default_pipeline_with_a_job_2_0_cant_run_gets_the_2_0_default(): void
    {
        $config = self::v1Config();
        Arr::set($config, 'pipelines.audio.jobs.mp3', 'Folklore\\Mediatheque\\Jobs\\Audio\\Mp3');
        // Equal to the 1.x default: HEVC doesn't run on ffmpeg
        $video = Arr::get($config, 'pipelines.video');

        $conversion = LegacyConfig::convert($config, []);

        $this->assertSame(Arr::get(V1Defaults::config(), 'pipelines.video'), $video);
        $pipelines = $conversion->config['pipelines'] ?? [];
        $this->assertIsArray($pipelines);
        $this->assertArrayNotHasKey('video', $pipelines);
        $this->assertArrayNotHasKey('document', $pipelines);
        $renditions = Arr::get($pipelines, 'audio.renditions');
        $this->assertIsArray($renditions);
        $this->assertSame(['thumbnails', 'mp3'], array_keys($renditions));
    }

    /**
     * @param  Application  $app
     */
    protected function bootWithJobOptions($app): void
    {
        $app['config']->set('mediatheque', self::v1ConfigWithJobOptions());
    }

    #[Test]
    #[DefineEnvironment('bootWithJobOptions')]
    public function the_package_reads_the_options_of_1x_jobs_as_the_upgrade_writes_them(): void
    {
        $config = app(MediathequeConfig::class);

        $this->assertSame(['codec' => 'vp9', 'max_width' => 1920, 'max_height' => 1920, 'crf' => 14], $config->pipeline('video')->renditions['webm']->options);
        $this->assertSame(['codec' => 'h264', 'max_width' => 720], $config->pipeline('bumper_720')->renditions['h264']->options);
        $this->assertSame(['thumbnails'], array_keys($config->pipeline('audio')->renditions), 'No MP3, as in 1.x.');
        $this->assertSame(['width' => 800, 'height' => 200, 'color' => '#FF0000'], $config->pipeline('audio')->renditions['thumbnails']->options);
    }
}
