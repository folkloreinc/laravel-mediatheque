<?php

use Illuminate\Support\Arr;

/**
 * Upload
 */
$uploadConfig = config('mediatheque.routes.upload', []);
$uploadGroupConfig = Arr::only($uploadConfig, ['middleware', 'domain', 'prefix', 'namespace']);
$router->group($uploadGroupConfig, function ($router) {
    $controller = config('mediatheque.routes.upload.controller');

    $router->post('/', [
        'as' => 'mediatheque.upload',
        'uses' => $controller.'@index'
    ]);

    $router->post('pull', [
        'as' => 'mediatheque.upload.pull',
        'uses' => $controller.'@pull'
    ]);

    foreach (mediatheque()->types() as $type) {
        if ($type->canUpload()) {
            $name = $type->getName();
            $router->post($name, [
                'as' => 'mediatheque.upload.'.$name,
                'uses' => $controller.'@'.$name
            ]);
        }
    }
});
