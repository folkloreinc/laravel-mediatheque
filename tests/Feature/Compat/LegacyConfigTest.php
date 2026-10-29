<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Compat\LegacyConfig;
use Folklore\Mediatheque\Compat\V1Defaults;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Support\ConfigMerger;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Arr;
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
    public function the_1x_defaults_are_no_overrides(): void
    {
        $conversion = LegacyConfig::convert(self::v1Config(), ['public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => 'http://localhost/storage']]);

        $this->assertSame(['table_prefix' => 'mediatheque_', 'source' => 'public'], $conversion->config);
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
        $this->assertArrayNotHasKey('pipelines', $conversion->config);
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
}
