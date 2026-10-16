<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_default_config(): void
    {
        $this->assertSame('mediatheque_', config('mediatheque.table_prefix'));
    }

    #[Test]
    public function it_publishes_the_config(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'mediatheque-config', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists(config_path('mediatheque.php'));

        @unlink(config_path('mediatheque.php'));
    }

    #[Test]
    public function a_fresh_install_exposes_no_route(): void
    {
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->getActionName(), 'Folklore\\Mediatheque\\')
                || str_contains($route->uri(), 'mediatheque'));

        $this->assertSame([], $routes->map(fn (Route $route) => $route->uri())->values()->all());
    }
}
