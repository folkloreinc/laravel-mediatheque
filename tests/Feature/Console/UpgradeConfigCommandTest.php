<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * `mediatheque:upgrade --config` rewrites a 1.x config file in the 2.0
 * shape, with only the application's overrides.
 */
class UpgradeConfigCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = config_path('mediatheque.php');
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        foreach (['MEDIATHEQUE_JOB_TIMEOUT', 'FFMPEG_BIN', 'FFPROBE_BIN', 'MEDIA_BUCKET', 'APP_URL'] as $variable) {
            putenv($variable);
            unset($_ENV[$variable], $_SERVER[$variable]);
        }

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.1x.bak');
    }

    /**
     * A 1.x config file: the 1.x defaults as they were published, with
     * these overrides, and these replacements of their code.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $code
     */
    private function write1xConfig(array $overrides, array $code = []): void
    {
        $defaults = (string) file_get_contents(self::fixturePath('v1/config.php'));
        $defaults = strtr($defaults, $code);
        $end = (int) strrpos($defaults, '];');

        file_put_contents($this->path, str_replace(
            'return [',
            'return array_replace_recursive([',
            substr($defaults, 0, $end),
        ).'], '.var_export($overrides, true).');'.PHP_EOL);
    }

    #[Test]
    public function it_rewrites_a_1x_config_with_only_the_overrides(): void
    {
        $this->write1xConfig([
            'table_prefix' => 'legacy_',
            'pipelines' => ['video' => ['jobs' => ['webm' => null, 'hevc' => null, 'media_convert' => null, 'thumbnails' => ['count' => 3]]]],
            'uploads' => ['max_size' => 2048],
        ]);
        putenv('MEDIATHEQUE_JOB_TIMEOUT=900');

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));
        $output = Artisan::output();

        $this->assertFileExists($this->path.'.1x.bak');
        $config = require $this->path;
        $this->assertSame([
            'table_prefix' => 'legacy_',
            'ingest' => ['max_size' => 2048 * 1024],
            'pipelines' => [
                'video' => [
                    'renditions' => [
                        'h264' => ['op' => 'video.transcode', 'codec' => 'h264'],
                        'thumbnails' => ['op' => 'video.thumbnails', 'count' => 3, 'role' => 'thumbnail'],
                    ],
                ],
            ],
            'binaries' => ['ffmpeg' => '/usr/local/bin/ffmpeg', 'ffprobe' => '/usr/local/bin/ffprobe'],
            'jobs' => ['timeout' => 900, 'tries' => 3],
        ], $config);

        $this->assertStringContainsString('2.0 has no upload routes', $output);
        $this->assertStringContainsString("'cloud' => [", $output);
        $this->assertStringContainsString("storage_path('app/public')", $output);
    }

    #[Test]
    public function the_thumbnails_of_documents_are_kept(): void
    {
        $this->write1xConfig(['pipelines' => ['document' => ['jobs' => ['thumbnails' => ['count' => 1, 'resolution' => 72]]]]]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $config = require $this->path;
        $this->assertIsArray($config);
        $this->assertSame(
            ['document' => ['renditions' => ['thumbnails' => ['op' => 'document.thumbnails', 'count' => 1, 'resolution' => 72, 'role' => 'thumbnail']]]],
            $config['pipelines'] ?? null,
        );
        $this->assertStringNotContainsString('document.thumbnails', Artisan::output());
    }

    #[Test]
    public function a_config_in_the_2_0_shape_is_left_as_it_is(): void
    {
        file_put_contents($this->path, "<?php\n\nreturn ['source' => 'local'];\n");

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertStringContainsString('2.0 shape already', Artisan::output());
        $this->assertFileDoesNotExist($this->path.'.1x.bak');
    }

    #[Test]
    public function the_1x_defaults_become_the_environment_variables_they_read(): void
    {
        $this->write1xConfig([]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertSame([
            'binaries' => ['ffmpeg' => '/usr/local/bin/ffmpeg', 'ffprobe' => '/usr/local/bin/ffprobe'],
            'jobs' => ['timeout' => 3600, 'tries' => 3],
        ], require $this->path);
        $this->assertStringContainsString('AUDIOWAVEFORM_BIN', Artisan::output());
    }

    #[Test]
    public function env_calls_keep_the_default_of_the_1x_file_not_the_value_of_this_machine(): void
    {
        $this->write1xConfig(['table_prefix' => 'legacy_'], [
            "env('FFPROBE_BIN', '/usr/local/bin/ffprobe')" => "env('FFPROBE_BIN', '/usr/bin/ffprobe')",
            'return [' => "return [\n    'archive' => ['bucket' => env('MEDIA_BUCKET', 'media')],",
        ]);
        foreach (['FFMPEG_BIN=/opt/homebrew/bin/ffmpeg', 'FFPROBE_BIN=/opt/homebrew/bin/ffprobe', 'MEDIATHEQUE_JOB_TIMEOUT=900', 'MEDIA_BUCKET=local-bucket'] as $variable) {
            putenv($variable);
        }

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $written = (string) file_get_contents($this->path);
        $this->assertStringContainsString("'ffmpeg' => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg')", $written);
        $this->assertStringContainsString("'ffprobe' => env('FFPROBE_BIN', '/usr/bin/ffprobe')", $written);
        $this->assertStringContainsString("'timeout' => (int) env('MEDIATHEQUE_JOB_TIMEOUT', 3600)", $written);
        $this->assertStringContainsString("'bucket' => env('MEDIA_BUCKET', 'media')", $written);
        $this->assertStringNotContainsString('/opt/homebrew', $written);
        $this->assertStringNotContainsString('900', $written);
        $this->assertStringNotContainsString('local-bucket', $written);

        // Each environment reads its own values
        $config = require $this->path;
        $this->assertIsArray($config);
        $this->assertSame(['ffmpeg' => '/opt/homebrew/bin/ffmpeg', 'ffprobe' => '/opt/homebrew/bin/ffprobe'], $config['binaries'] ?? null);
        $this->assertSame(['timeout' => 900, 'tries' => 3], $config['jobs'] ?? null);
        $this->assertSame(['bucket' => 'local-bucket'], $config['archive'] ?? null);
    }

    #[Test]
    public function sources_are_compared_with_the_disks_as_this_machine_reads_both(): void
    {
        // The default of APP_URL is production's; this machine sets its own,
        // which the disk reads too
        $this->write1xConfig([], ["'url' => env('APP_URL').'/storage'" => "'url' => env('APP_URL', 'https://example.com').'/storage'"]);
        putenv('APP_URL=https://local.test');
        config(['filesystems.disks.public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => 'https://local.test/storage', 'visibility' => 'public']]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertStringNotContainsString('The 1.x source "public"', Artisan::output());
    }

    #[Test]
    public function it_lists_the_code_that_reads_this_machine(): void
    {
        $this->write1xConfig([], ['return [' => "return [\n    'archive' => ['tmp' => sys_get_temp_dir(), 'token' => \\Illuminate\\Support\\Env::get('TOKEN')],"]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/mediatheque\.php\.1x\.bak:\d+ \.+ sys_get_temp_dir\(\)/', $output);
        $this->assertStringNotContainsString('storage_path()', $output, 'The rewritten file writes path helpers as calls.');
    }

    #[Test]
    public function it_reports_a_1x_source_that_the_disk_of_the_same_name_doesnt_store(): void
    {
        $this->write1xConfig(['sources' => ['public' => ['driver' => 'local', 'path' => '/var/www/public/files', 'url' => '/files']]]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertStringContainsString('The 1.x source "public"', Artisan::output());
    }
}
