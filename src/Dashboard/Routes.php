<?php

namespace Folklore\Mediatheque\Dashboard;

use Folklore\Mediatheque\Dashboard\Controllers\ApiController;
use Folklore\Mediatheque\Dashboard\Controllers\PageController;
use Illuminate\Routing\Router;

/**
 * The dashboard's routes, which an application registers with
 * `Route::mediathequeDashboard()` inside its own middleware group
 * (docs/decisions/0008, 0017). Its JSON endpoints are for its page only:
 * they are not a versioned API.
 */
final class Routes
{
    public static function register(Router $router, string $prefix = 'mediatheque'): void
    {
        $router->prefix($prefix)->name('mediatheque.dashboard.')->middleware(Authorize::class.':view')->group(function (Router $router) {
            $router->get('/', [PageController::class, 'show'])->name('show');
            $router->get('assets/{file}', [PageController::class, 'asset'])->where('file', '[A-Za-z0-9_-]+\.(js|css)')->name('asset');

            $router->prefix('api')->name('api.')->group(function (Router $router) {
                $router->get('overview', [ApiController::class, 'overview'])->name('overview');
                $router->get('runs', [ApiController::class, 'runs'])->name('runs');
                $router->get('runs/{run}', [ApiController::class, 'run'])->name('run');
                $router->get('failures', [ApiController::class, 'failures'])->name('failures');
                $router->get('durations', [ApiController::class, 'durations'])->name('durations');
                $router->get('throughput', [ApiController::class, 'throughput'])->name('throughput');

                $router->middleware(Authorize::class.':operate')->group(function (Router $router) {
                    $router->post('runs/{run}/retry', [ApiController::class, 'retry'])->name('retry');
                    $router->post('runs/{run}/cancel', [ApiController::class, 'cancel'])->name('cancel');
                    $router->post('runs/{run}/refresh', [ApiController::class, 'refresh'])->name('refresh');
                    $router->post('media/{media}/probe', [ApiController::class, 'probe'])->name('probe');
                });
            });
        });
    }
}
