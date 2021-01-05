<?php
namespace Folklore\Mediatheque;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Pipeline\Factory as PipelineFactoryContract;
use Closure;
use InvalidArgumentException;
use Exception;

class PipelineManager implements PipelineFactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The registered custom pipeline creators.
     *
     * @var array
     */
    protected $customPipelines = [];

    /**
     * The array of created "pipelines".
     *
     * @var array
     */
    protected $pipelines = [];

    /**
     * Create a new manager instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Get a pipeline instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function pipeline($name)
    {
        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (!isset($this->pipelines[$name])) {
            $this->pipelines[$name] = $this->createPipeline($name);
        }

        return $this->pipelines[$name];
    }

    /**
     * Create a new pipeline.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createPipeline($name)
    {
        // First, we will determine if a custom driver creator exists for the given driver and
        // if it does not we will check for a creator method for the driver. Custom creator
        // callbacks allow developers to build their own "drivers" easily using Closures.
        $pipeline = null;
        if (isset($this->customPipelines[$name])) {
            $pipeline = $this->createCustomPipeline($name);
        } else {
            $config = $this->app['config']->get("mediatheque.pipelines.$name");
            $pipeline = $this->createPipelineInstance($name, $config);
        }

        if (!is_null($pipeline)) {
            return $pipeline;
        }

        throw new InvalidArgumentException("Pipeline [$name] doesn't exists.");
    }

    /**
     * Create a new pipeline instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createPipelineInstance($name, $config)
    {
        $pipeline = null;
        if (is_string($config)) {
            $pipeline = $this->app->make($config);
        } elseif (is_array($config)) {
            $options = Arr::except($config, ['jobs']);
            $jobs = data_get($config, 'jobs', []);
            $pipeline = $this->app->make(PipelineContract::class);
            $pipeline->setOptions($options);
            $pipeline->setJobs($jobs);
        } elseif (is_object($config)) {
            $pipeline = $config;
        }

        if (!is_null($pipeline)) {
            $pipeline->setName($name);
        }

        return $pipeline;
    }

    /**
     * Create a custom pipeline
     *
     * @param  string  $name
     * @return mixed
     */
    protected function createCustomPipeline($name)
    {
        $customPipeline = $this->customPipelines[$name];
        return $customPipeline instanceof Closure
            ? $customPipeline($this->app, $name)
            : $this->createPipelineInstance($name, $customPipeline);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string    $name
     * @param  string|array|Closure  $pipeline
     * @return $this
     */
    public function extend($name, $pipeline)
    {
        $this->customPipelines[$name] = $pipeline;

        return $this;
    }

    /**
     * Get all of the created "drivers".
     *
     * @return array
     */
    public function getPipelines()
    {
        return $this->pipelines;
    }

    /**
     * Check if a pipeline exists
     *
     * @param string $name
     * @return boolean
     */
    public function hasPipeline($name)
    {
        return !is_null(
            $this->app['config']->get('mediatheque.pipelines.' . $name)
        ) || isset($this->customPipelines[$name]);
    }
}
