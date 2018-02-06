<?php

namespace Folklore\Mediatheque;

class Mediatheque
{
    protected $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    public function routes()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $config = $this->app['config']->get('mediatheque.routes', []);
        $router = app()->bound('router') ? app('router') : app();
        $groupConfig = array_except($config, ['controllers']);
        $router->group($groupConfig, function ($router) {
            $types = array_keys($config->get('mediatheque.types'));
            $routesPath = is_file(base_path('routes/mediatheque.php')) ?
                base_path('routes/mediatheque.php') : (__DIR__ . '/../../routes/mediatheque.php');
            require $routesPath;
        });
    }
}
