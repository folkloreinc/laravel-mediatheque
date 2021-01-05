<?php
namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Sources\LocalSource;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Folklore\Mediatheque\Exception\InvalidSourceException;
use Folklore\Mediatheque\Contracts\Source\Factory as SourceFactoryContract;
use Illuminate\Filesystem\Filesystem;

class SourceManager implements SourceFactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The filesystem
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators = [];

    /**
     * The array of created "sources".
     *
     * @var array
     */
    protected $sources = [];

    /**
     * Create a new manager instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public function __construct($app, Filesystem $files)
    {
        $this->app = $app;
        $this->files = $files;
    }

    /**
     * Create an instance of the Imagine Gd driver.
     *
     * @return \Folklore\Mediatheque\Sources\LocalSource
     */
    protected function createLocalDriver($config)
    {
        return new LocalSource($config, $this->files);
    }

    /**
     * Create an instance of the Imagine Imagick driver.
     *
     * @return \Folklore\Mediatheque\Sources\FilesystemSource
     */
    protected function createFilesystemDriver($config)
    {
        return new FilesystemSource($config, $this->files);
    }

    /**
     * Get a source instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function source($name = null)
    {
        $name = $name ?: $this->getDefaultSource();

        if (is_null($name)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unable to resolve NULL source for [%s].',
                    static::class
                )
            );
        }

        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (!isset($this->sources[$name])) {
            $this->sources[$name] = $this->createSource($name);
        }

        return $this->sources[$name];
    }

    /**
     * Create a new driver instance.
     *
     * @param  string  $driver
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createSource($name)
    {
        $config = $this->getConfig($name);
        if (!$config) {
            throw new InvalidSourceException("Source [$name] not found.");
        }
        $driver = $config['driver'];
        $method = 'create' . ucfirst($driver) . 'Driver';

        // We'll check to see if a creator method exists for the given driver. If not we
        // will check for a custom driver creator, which allows developers to create
        // sources using their own customized driver creator Closure to create it.
        if (isset($this->customCreators[$driver])) {
            return $this->customCreators[$driver]($this->app, $config);
        } elseif (method_exists($this, $method)) {
            return $this->$method($config);
        }

        throw new InvalidSourceException(
            "Driver [$driver] not supported for source [$name]."
        );
    }

    /**
     * Call a custom driver creator.
     *
     * @param  string  $driver
     * @return mixed
     */
    protected function callCustomCreator($driver)
    {
        return $this->customCreators[$driver]($this->app);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string    $driver
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Get the source config
     *
     * @param  string  $name
     * @return array|null
     */
    protected function getConfig($name = null)
    {
        $name = $name ?: $this->getDefaultSource();
        return $this->app['config']['mediatheque.sources.' . $name];
    }

    /**
     * Get the default source name.
     *
     * @return string
     */
    public function getDefaultSource()
    {
        return $this->app['config']['mediatheque.source'];
    }

    /**
     * Set the default source name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultSource($name)
    {
        $this->app['config']['mediatheque.source'] = $name;
    }

    /**
     * Get all of the created "sources".
     *
     * @return array
     */
    public function getSources()
    {
        return $this->sources;
    }

    /**
     * Check if a source exists
     *
     * @param string $name The source name
     * @return boolean
     */
    public function hasSource($name)
    {
        return isset($this->app['config']['mediatheque.sources.' . $name]);
    }

    /**
     * Dynamically call the default source instance.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->source()->$method(...$parameters);
    }
}
