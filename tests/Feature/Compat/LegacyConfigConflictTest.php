<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger;
use Illuminate\Support\Arr;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 1.x config whose `public` source is not Laravel's `public` disk, as the
 * 1.x default of 2017 and 2018: the package warns when it boots.
 */
class LegacyConfigConflictTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = require self::fixturePath('v1/config.php');
        $config = is_array($config) ? $config : [];
        Arr::set($config, 'sources.public', ['driver' => 'local', 'path' => public_path('files'), 'url' => '/files']);

        $app['config']->set('mediatheque', $config);
        $app['config']->set('logging.channels.test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $app['config']->set('logging.default', 'test');
    }

    #[Test]
    public function the_package_warns_about_a_source_that_the_disk_doesnt_store(): void
    {
        $logger = app('log')->channel('test');
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(\Monolog\Logger::class, $monolog);
        $handler = $monolog->getHandlers()[0];
        $this->assertInstanceOf(TestHandler::class, $handler);

        $this->assertTrue($handler->hasWarningThatContains('The 1.x source "public"'));
        $this->assertSame(storage_path('app/public'), config('filesystems.disks.public.root'), 'The disk is left as it is.');
    }
}
