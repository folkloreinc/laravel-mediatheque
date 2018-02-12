<?php

namespace Folklore\Mediatheque;

use \Folklore\Mediatheque\Contracts\Pipeline as PipelineContract;

class Mediatheque
{
    protected $app;

    protected $pipelines = [];

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
            $routesPath = is_file(base_path('routes/mediatheque.php')) ?
                base_path('routes/mediatheque.php') : (__DIR__ . '/../../routes/mediatheque.php');
            require $routesPath;
        });
    }

    public function pipeline($name)
    {
        $pipeline = array_get($this->pipelines, $name, null);
        if (is_null($pipeline)) {
            throw new Exception('Pipeline "'.$name.'" doesn\'t exists');
        }

        if (is_string($pipeline)) {
            $pipeline = app($pipeline);
        } elseif (is_array($pipeline)) {
            $options = array_except($pipeline, ['jobs']);
            $jobs = array_get($pipeline, 'jobs', []);
            $pipeline = app(PipelineContract::class);
            $pipeline->setOptions($options);
            $pipeline->setJobs($jobs);
        }

        $pipeline->setName($name);

        return $pipeline;
    }

    public function addPipeline($name, $pipeline)
    {
        $this->pipelines[$name] = $pipeline;
        return $this;
    }

    public function setPipelines($pipelines)
    {
        $this->pipelines = $pipelines;
        return $this;
    }
}
