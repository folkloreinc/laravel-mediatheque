<?php

namespace Folklore\Mediatheque\Tests\Feature\Images;

use Folklore\Image\ImageServiceProvider;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Mediatheque\Images\MediaResolver;
use Folklore\Mediatheque\ServiceProvider;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application where `laravel-image`'s resolvers are built before this
 * package's provider boots (#60).
 */
class EarlyResolversTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImageServiceProvider::class,
            BuildsImageResolversEarly::class,
            ServiceProvider::class,
        ];
    }

    #[Test]
    public function the_media_resolver_is_registered_in_resolvers_built_before_the_provider_booted(): void
    {
        $this->assertTrue(BuildsImageResolversEarly::$built);
        $this->assertInstanceOf(MediaResolver::class, app(SourceResolvers::class)->named('media'));
    }
}

/**
 * Another application provider that builds images while it boots.
 */
class BuildsImageResolversEarly extends BaseServiceProvider
{
    public static bool $built = false;

    public function boot(): void
    {
        $this->app->make(SourceResolvers::class);
        self::$built = true;
    }
}
