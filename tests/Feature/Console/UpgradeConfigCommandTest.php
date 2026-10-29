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
        putenv('MEDIATHEQUE_JOB_TIMEOUT');
        unset($_ENV['MEDIATHEQUE_JOB_TIMEOUT'], $_SERVER['MEDIATHEQUE_JOB_TIMEOUT']);

        parent::tearDown();
    }

    private function cleanUp(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.1x.bak');
    }

    /**
     * A 1.x config file: the 1.x defaults, with these overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function write1xConfig(array $overrides): void
    {
        file_put_contents($this->path, sprintf(
            "<?php\n\nreturn array_replace_recursive(require %s, %s);\n",
            var_export(self::fixturePath('v1/config.php'), true),
            var_export($overrides, true),
        ));
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
            'jobs' => ['timeout' => 900],
        ], $config);
        $this->assertStringContainsString("(int) env('MEDIATHEQUE_JOB_TIMEOUT', 900)", (string) file_get_contents($this->path));

        $this->assertStringContainsString('2.0 has no upload routes', $output);
        $this->assertStringContainsString("'cloud' => [", $output);
        $this->assertStringContainsString("storage_path('app/public')", $output);
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
    public function the_1x_defaults_become_an_empty_config(): void
    {
        $this->write1xConfig([]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertSame([], require $this->path);
    }

    #[Test]
    public function it_reports_a_1x_source_that_the_disk_of_the_same_name_doesnt_store(): void
    {
        $this->write1xConfig(['sources' => ['public' => ['driver' => 'local', 'path' => '/var/www/public/files', 'url' => '/files']]]);

        $this->assertSame(0, Artisan::call('mediatheque:upgrade', ['--config' => true]));

        $this->assertStringContainsString('The 1.x source "public"', Artisan::output());
    }
}
