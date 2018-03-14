<?php
/**
 * Api
 */
$apiConfig = config('mediatheque.routes.api', []);
$apiGroupConfig = array_only($apiConfig, ['middleware', 'domain', 'prefix', 'namespace']);
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
