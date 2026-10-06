<?php

namespace Folklore\Mediatheque\Tests\Unit;

use ErrorException;
use Folklore\Mediatheque\Router;
use Folklore\Mediatheque\Tests\TestCase;

class RouterTest extends TestCase
{
    public function test_upload_routes_are_named_by_type()
    {
        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();

        $this->assertTrue($routes->hasNamedRoute('mediatheque.upload'));
        $this->assertTrue($routes->hasNamedRoute('mediatheque.upload.image'));
        $this->assertTrue($routes->hasNamedRoute('mediatheque.upload.video'));
        $this->assertFalse($routes->hasNamedRoute('mediatheque..upload.image'));
    }

    public function test_router_has_no_dynamic_property()
    {
        set_error_handler(function ($severity, $message) {
            throw new ErrorException($message, 0, $severity);
        }, E_DEPRECATED);

        try {
            $router = new Router(app('router'), app('mediatheque'));
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(Router::class, $router);
    }
}
