<?php

$router = !isset($router) ? app('router') : $router;
$prefix = config('mediatheque.route_prefix');
$prefix = config('mediatheque.route_namespace');
$types = array_keys(config('mediatheque.mimes'));

$router->group([
    'prefix' => $prefix,
    'namespace' => $namespace,
    'middleware' => ['api']
], function ($router) use ($types) {
    /**
     * Upload
     */
    $router->group([
        'prefix' => 'upload',
    ], function ($router) use ($types) {
        $router->post('/', [
            'as' => 'mediatheque.upload',
            'uses' => 'UploadController@index'
        ]);

        $router->post('/pull', [
            'as' => 'mediatheque.upload.pull',
            'uses' => 'UploadController@pull'
        ]);

        foreach ($types as $type) {
            $router->post('/'.$type, [
                'as' => 'mediatheque.upload.'.$type,
                'uses' => 'UploadController@'.$type
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
            $router->resource($type, studly_case($type).'Controller', [
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
});
