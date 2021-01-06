<?php

namespace Folklore\Mediatheque;

use Illuminate\Support\Arr;
use Illuminate\Contracts\Routing\Registrar;
use Folklore\Mediatheque\Http\Controllers\UploadController;
use Folklore\Mediatheque\Http\Controllers\MediaController;

class Router
{
    protected $router;

    public function __construct(Registrar $router, Mediatheque $mediatheque)
    {
        $this->router = $router;
        $this->mediatheque = $mediatheque;
    }

    public function mediatheque($opts = [])
    {
        $this->router->group(
            Arr::only($opts, ['middleware', 'domain', 'prefix', 'namespace']),
            function () use ($opts) {
                $namePrefix = data_get($opts, 'name', 'mediatheque.');
                $types = $this->mediatheque->types();

                $apiOptions = isset($opts['api']) ? $opts['api'] : [];
                if ($apiOptions !== false) {
                    $apiOptions['name'] = data_get($apiOptions, 'name', $namePrefix . 'api.');
                    $this->apiRoutes($types, $apiOptions);
                }

                $uploadOptions = isset($opts['upload']) ? $opts['upload'] : [];
                if ($uploadOptions !== false) {
                    $this->uploadRoutes($types, $uploadOptions);
                }
            }
        );
    }

    private function apiRoutes($types, $opts = [])
    {
        $this->router->group(
            Arr::only($opts, ['middleware', 'domain', 'prefix', 'namespace']),
            function () use ($types, $opts) {
                $defaultController = data_get($opts, 'controllers.media', MediaController::class);
                foreach ($types as $type) {
                    $name = $type->getName();
                    $controller = data_get($opts, 'controllers.' . $name, $defaultController);
                    $this->router
                        ->apiResource($name, $controller)
                        ->names([
                            'index' => $namePrefix . $name . '.index',
                            'show' => $namePrefix . $name . '.show',
                            'store' => $namePrefix . $name . '.store',
                            'update' => $namePrefix . $name . '.update',
                            'destroy' => $namePrefix . $name . '.destroy',
                        ])
                        ->parameters([
                            $name => $name,
                        ]);
                }
            }
        );
    }

    private function uploadRoutes($types, $opts = [])
    {
        $this->router->group(
            Arr::only($opts, ['middleware', 'domain', 'prefix', 'namespace']),
            function () use ($types, $opts) {
                $controller = data_get($opts, 'controller', UploadController::class);

                $this->router->post('/', [
                    'as' => $namePrefix . 'upload',
                    'uses' => $controller . '@index',
                ]);

                $this->router->post('pull', [
                    'as' => $namePrefix . 'upload.pull',
                    'uses' => $controller . '@pull',
                ]);

                foreach ($types as $type) {
                    if ($type->canUpload()) {
                        $name = $type->getName();
                        $this->router->post($name, [
                            'as' => 'mediatheque.upload.' . $name,
                            'uses' => $controller . '@' . $name,
                        ]);
                    }
                }
            }
        );
    }
}
