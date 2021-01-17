<?php
namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Contracts\Metadata\Factory as MetadataFactory;
use Folklore\Mediatheque\Contracts\Metadata\Reader as MetadataReader;

class MetadataManager implements MetadataFactory
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
    protected $customReaders = [];

    /**
     * The array of created "metadatas" reader.
     *
     * @var array
     */
    protected $instances = [];

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
     * Get a metadata reader instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function metadata(string $name): MetadataReader
    {
        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (!isset($this->instances[$name])) {
            $this->instances[$name] = $this->createReader($name);
        }

        return $this->instances[$name];
    }

    /**
     * Create a new type.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createReader($name)
    {
        // First, we will determine if a custom driver creator exists for the given driver and
        // if it does not we will check for a creator method for the driver. Custom creator
        // callbacks allow developers to build their own "drivers" easily using Closures.
        $reader = null;
        if (isset($this->customReaders[$name])) {
            $reader = $this->createCustomReader($name);
        } else {
            $config = $this->app['config']->get(
                'mediatheque.metadatas.' . $name
            );
            $reader = $this->createReaderInstance($name, $config);
        }

        if (!is_null($reader)) {
            return $reader;
        }

        throw new InvalidArgumentException(
            "Metadata reader [$name] doesn't exists."
        );
    }

    /**
     * Create a new reader instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createReaderInstance($name, $config)
    {
        $reader = is_string($config) ? $this->app->make($config) : $config;
        $reader->setName($name);
        return $reader;
    }

    /**
     * Call a custom driver creator.
     *
     * @param  string  $name
     * @return mixed
     */
    protected function createCustomReader($name)
    {
        $customReader = $this->customReaders[$name];
        return $customReader instanceof Closure
            ? $customReader($this->app, $name)
            : $this->createReaderInstance($name, $customReader);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string    $name
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($name, $reader)
    {
        $this->customReaders[$name] = $reader;

        return $this;
    }

    /**
     * Get all of the created "metadata instances".
     *
     * @return array
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * Check if a metadata reader exists
     *
     * @param string $name
     * @return boolean
     */
    public function hasMetadata(string $name): bool
    {
        return !is_null(
            $this->app['config']->get('mediatheque.metadatas.' . $name)
        ) || isset($this->customReaders[$name]);
    }
}
