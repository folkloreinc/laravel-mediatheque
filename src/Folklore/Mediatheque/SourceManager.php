<?php namespace Folklore\Mediatheque;

use Folklore\Mediatheque\Sources\LocalSource;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Folklore\Mediatheque\Exception\InvalidSourceException;

use Illuminate\Support\Manager;

class SourceManager extends Manager
{
    /**
     * Create an instance of the Imagine Gd driver.
     *
     * @return \Folklore\Mediatheque\Sources\LocalSource
     */
    protected function createLocalDriver($config)
    {
        return new LocalSource($config, app('files'));
    }

    /**
     * Create an instance of the Imagine Imagick driver.
     *
     * @return \Folklore\Mediatheque\Sources\FilesystemSource
     */
    protected function createFilesystemDriver($config)
    {
        return new FilesystemSource($config, app('files'));
    }

    /**
     * Create a new driver instance.
     *
     * @param  string  $driver
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createDriver($source)
    {
        $config = $this->app['config']['mediatheque.files.sources.'.$source];
        if (!$config) {
            throw new InvalidSourceException("Source [$source] not found.");
        }
        $driver = $config['driver'];
        $method = 'create'.ucfirst($driver).'Driver';

        // We'll check to see if a creator method exists for the given driver. If not we
        // will check for a custom driver creator, which allows developers to create
        // drivers using their own customized driver creator Closure to create it.
        if (isset($this->customCreators[$driver])) {
            return $this->customCreators[$driver]($this->app, $config);
        } elseif (method_exists($this, $method)) {
            return $this->$method($config);
        }

        throw new InvalidSourceException("Driver [$driver] not supported for source [$source].");
    }

    /**
     * Get the default image driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->app['config']['mediatheque.files.source'];
    }

    /**
     * Set the default image driver name.
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultDriver($name)
    {
        $this->app['config']['mediatheque.files.source'] = $name;
    }
}
