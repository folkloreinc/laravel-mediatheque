<?php

namespace Folklore\Mediatheque;

use Illuminate\Support\Arr;
use Illuminate\Contracts\Routing\Registrar;
use Folklore\Mediatheque\Http\Controllers\UploadController;
use Folklore\Mediatheque\Http\Controllers\MediaController;

class Router
{
    protected $router;

    protected $namePrefix = 'mediatheque.';

    protected $prefix = 'mediatheque';

    protected $middleware = null;

    public function __construct(Registrar $router, Mediatheque $mediatheque)
    {
        $this->router = $router;
        $this->mediatheque = $mediatheque;
    }

    public function group($group)
    {
        $registrar = $this->router;

        if (isset($this->prefix)) {
            $registrar = $registrar->prefix($this->prefix);
        }

        if (isset($this->middleware)) {
            $registrar = $registrar->middleware($this->middleware);
        }

        return $registrar->group($group);
    }

    public function mediatheque($opts = [])
    {
        $this->group(function () use ($opts) {
            $apiOptions = isset($opts['api']) ? $opts['api'] : [];
            if ($apiOptions !== false) {
                $apiOptions['name'] = data_get($apiOptions, 'name', $this->namePrefix . 'api.');
                $this->api($apiOptions);
            }

            $uploadOptions = isset($opts['upload']) ? $opts['upload'] : [];
            if ($uploadOptions !== false) {
                $this->upload($uploadOptions);
            }
        });
    }

    public function api($opts = [])
    {
        $this->router->group(
            Arr::only($opts, ['middleware', 'domain', 'prefix', 'namespace']),
            function () use ($opts) {
                $types = $this->mediatheque->types();
                $defaultController = data_get($opts, 'controllers.media', MediaController::class);
                $allowedTypes = data_get($opts, 'types');
                foreach ($types as $type) {
                    $name = $type->name();
                    if (is_null($allowedTypes) || in_array($name, $allowedTypes)) {
                        $controller = data_get($opts, 'controllers.' . $name, $defaultController);
                        $this->router
                            ->apiResource($name, $controller)
                            ->names([
                                'index' => $this->namePrefix . $name . '.index',
                                'show' => $this->namePrefix . $name . '.show',
                                'store' => $this->namePrefix . $name . '.store',
                                'update' => $this->namePrefix . $name . '.update',
                                'destroy' => $this->namePrefix . $name . '.destroy',
                            ])
                            ->parameters([
                                $name => $name,
                            ]);
                    }
                }
            }
        );
    }

    public function upload($opts = [])
    {
        $this->router->group(
            Arr::only($opts, ['middleware', 'domain', 'prefix', 'namespace']),
            function () use ($opts) {
                $types = $this->mediatheque->types();
                $controller = data_get($opts, 'controller', UploadController::class);
                $allowedTypes = data_get($opts, 'types');

                $this->router->post('/', [
                    'as' => $this->namePrefix . 'upload',
                    'uses' => $controller . '@index',
                ]);

                $this->router->post('pull', [
                    'as' => $this->namePrefix . 'upload.pull',
                    'uses' => $controller . '@pull',
                ]);

                foreach ($types as $type) {
                    $name = $type->name();
                    // prettier-ignore
                    if ($type->canUpload() &&
                        (is_null($allowedTypes) || in_array($name, $allowedTypes))
                    ) {
                        $this->router->post($name, [
                            'as' => $this->namePrefix . '.upload.' . $name,
                            'uses' => $controller . '@' . $name,
                        ]);
                    }
                }
            }
        );
    }

    public function setNamePrefix($prefix)
    {
        $this->namePrefix = $prefix;
    }

    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

    public function setMiddleware($middleware)
    {
        $this->middleware = $middleware;
    }
}
