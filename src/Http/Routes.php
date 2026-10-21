<?php

namespace Folklore\Mediatheque\Http;

use Folklore\Mediatheque\Http\Controllers\UploadController;
use Illuminate\Routing\Router;

/**
 * The package's routes, which an application registers with
 * `Route::mediatheque()` inside its own middleware group
 * (docs/decisions/0008). Nothing is registered otherwise.
 */
final class Routes
{
    public static function register(Router $router, string $prefix = 'mediatheque'): void
    {
        $router->prefix($prefix)->name('mediatheque.')->group(function (Router $router) {
            $router->post('uploads', [UploadController::class, 'store'])->name('uploads.store');
            $router->post('uploads/sign', [UploadController::class, 'sign'])->name('uploads.sign');
            $router->get('uploads/{upload}/parts', [UploadController::class, 'listParts'])->name('uploads.parts.index');
            $router->post('uploads/{upload}/parts', [UploadController::class, 'signParts'])->name('uploads.parts.sign');
            $router->post('uploads/{upload}/complete', [UploadController::class, 'complete'])->name('uploads.complete');
            $router->delete('uploads/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');
        });
    }
}
