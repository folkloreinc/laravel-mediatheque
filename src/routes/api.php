<?php
/**
 * Api
 */
$apiConfig = config('mediatheque.routes.api', []);
$apiGroupConfig = array_only($apiConfig, ['middleware', 'domain', 'prefix', 'namespace']);
$router->group($apiGroupConfig, function ($router) {
    $defaultController = config('mediatheque.routes.api.controllers.media');
    foreach (mediatheque()->types() as $type) {
        $name = $type->getName();
        $controller = config('mediatheque.routes.api.controllers.'.$name, $defaultController);
        $router->resource($name, $controller, [
            'type' => $name,
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
});
