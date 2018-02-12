<?php

/**
 * Upload
 */
$uploadGroupConfig = config('mediatheque.routes.upload', []);
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

    $types = config('mediatheque.types');
    foreach ($types as $name => $type) {
        $canUpload = array_get($type, 'upload', true);
        if ($canUpload) {
            $router->post($name, [
                'as' => 'mediatheque.upload.'.$name,
                'uses' => $controller.'@'.$name
            ]);
        }
    }
});

/**
 * Api
 */
$apiGroupConfig = config('mediatheque.routes.api', []);
$router->group($apiGroupConfig, function ($router) {
    $types = config('mediatheque.types');
    foreach ($types as $name => $type) {
        $controller = config('mediatheque.routes.api.controllers.'.$name);
        if (!is_null($controller)) {
            $router->resource($name, $controller, [
                'except' => ['create', 'edit'],
                'names' => [
                    'index' => 'mediatheque.api.'.$name.'.index',
                    'show' => 'mediatheque.api.'.$name.'.show',
                    'store' => 'mediatheque.api.'.$name.'.store',
                    'update' => 'mediatheque.api.'.$name.'.update',
                    'destroy' => 'mediatheque.api.'.$name.'.destroy'
                ]
            ]);
        }
    }
});
