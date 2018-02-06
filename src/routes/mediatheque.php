<?php

/**
 * Upload
 */
$router->group([
    'prefix' => 'upload',
], function ($router) use ($types) {
    $controller = array_get($controllers, 'upload', 'UploadController');
    $router->post('/', [
        'as' => 'mediatheque.upload',
        'uses' => $controller.'@index'
    ]);

    $router->post('/pull', [
        'as' => 'mediatheque.upload.pull',
        'uses' => $controller.'@pull'
    ]);

    foreach ($types as $type) {
        $router->post('/'.$type, [
            'as' => 'mediatheque.upload.'.$type,
            'uses' => $controller.'@'.$type
        ]);
    }
});

/**
 * Api
 */
$router->group([
    'prefix' => 'api',
], function ($router) use ($types) {
    foreach ($types as $type) {
        $controller = array_get($controllers, $type, studly_case($type).'Controller');
        $router->resource($type, $controller, [
            'except' => ['create', 'edit'],
            'names' => [
                'index' => 'mediatheque.api.'.$type.'.index',
                'show' => 'mediatheque.api.'.$type.'.show',
                'store' => 'mediatheque.api.'.$type.'.store',
                'update' => 'mediatheque.api.'.$type.'.update',
                'destroy' => 'mediatheque.api.'.$type.'.destroy'
            ]
        ]);
    }
});
