<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application that still has its 1.x `config/mediatheque.php`: the
 * package reads it in the 2.0 shape when it boots.
 */
class LegacyConfigFileTest extends TestCase
{
    protected function setUp(): void
    {
        Deprecation::flush();

        parent::setUp();
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mediatheque', require self::fixturePath('v1/config.php'));
    }

    #[Test]
    public function the_package_reads_a_1x_config_file_with_a_deprecation(): void
    {
        $config = app(MediathequeConfig::class);
        $config->validate();

        $this->assertSame('mediatheque_', $config->tablePrefix());
        $this->assertSame(['h264', 'poster', 'thumbnails'], array_keys($config->pipeline('video')->renditions), 'The 1.x defaults give way to the 2.0 ones.');
        $this->assertNull($config->type('font')->pipeline);
        $this->assertIsArray(config('filesystems.disks.cloud'), 'The 1.x sources are disks.');
        $this->assertNull(config('mediatheque.sources'));
        $this->assertSame(['config/mediatheque.php has the 1.x shape: rewrite it with `php artisan mediatheque:upgrade --config`.'], Deprecation::reported());
    }
}
