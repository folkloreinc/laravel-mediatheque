<?php

/**
 * Upload
 */
$uploadConfig = config('mediatheque.routes.upload', []);
$uploadGroupConfig = array_only($uploadConfig, ['middleware', 'domain', 'prefix', 'namespace']);
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
