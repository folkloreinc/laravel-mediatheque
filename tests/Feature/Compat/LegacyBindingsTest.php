<?php

namespace Folklore\Mediatheque\Tests\Feature\Compat;

use Folklore\Mediatheque\Compat\Deprecation;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;

/**
 * A 1.x application that binds its media model to the contract, as 1.x
 * read it.
 */
class LegacyBindingsTest extends TestCase
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

        $app->bind(MediaContract::class, TenantMedia::class);
    }

    #[Test]
    public function a_1x_binding_of_the_media_contract_is_the_media_model(): void
    {
        $this->assertSame(TenantMedia::class, Models::media());
        $this->assertSame([sprintf('Binding %s in the container is deprecated: set your model in config(\'mediatheque.models.media\').', MediaContract::class)], Deprecation::reported());
    }
}
