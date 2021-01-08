<?php
namespace Folklore\Mediatheque;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Type\Type as TypeContract;
use Folklore\Mediatheque\Contracts\Type\Factory as TypeFactoryContract;
use Folklore\Mediatheque\Support\Type;

class TypeManager implements TypeFactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The registered custom type creators.
     *
     * @var array
     */
    protected $customTypes = [];

    /**
     * The array of created "instances".
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
     * Get a type instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function type(string $name): TypeContract
    {
        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (!isset($this->instances[$name])) {
            $this->instances[$name] = $this->createType($name);
        }

        return $this->instances[$name];
    }

    /**
     * Get type of a path
     *
     * @param  string  $path
     * @return string
     */
    public function typeFromPath(string $path): ?TypeContract
    {
        return $this->types()->first(function ($type) use ($path) {
            return $type->pathIsType($path);
        });
    }

    /**
     * Get all types.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function types(): Collection
    {
        return collect(array_keys($this->app['config']->get('mediatheque.types')))
            ->merge(array_keys($this->customTypes))
            ->unique()
            ->values()
            ->map(function ($name) {
                return $this->type($name);
            });
    }

    /**
     * Create a new type.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createType($name)
    {
        // First, we will determine if a custom driver creator exists for the given driver and
        // if it does not we will check for a creator method for the driver. Custom creator
        // callbacks allow developers to build their own "drivers" easily using Closures.
        $type = null;
        if (isset($this->customTypes[$name])) {
            $type = $this->createCustomType($name);
        } else {
            $config = $this->app['config']->get("mediatheque.types.$name");
            $type = $this->createTypeInstance($name, $config);
        }

        if (!is_null($type)) {
            return $type;
        }

        throw new InvalidArgumentException("Type [$name] doesn't exists.");
    }

    /**
     * Create a new type instance.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    protected function createTypeInstance($name, $config): TypeContract
    {
        if (is_string($config)) {
            return $this->app->make($config);
        }

        if ($config instanceof TypeContract) {
            return $config;
        }

        return new Type($name, $config);
    }

    /**
     * Create a custom type
     *
     * @param  string  $name
     * @return mixed
     */
    protected function createCustomType($name)
    {
        $customType = $this->customTypes[$name];
        return $customType instanceof Closure
            ? $customType($this->app, $name)
            : $this->createTypeInstance($name, $customType);
    }

    /**
     * Register a custom type
     *
     * @param  string    $name
     * @param  string|array|\Closure  $type
     * @return $this
     */
    public function extend($name, $type)
    {
        $this->customTypes[$name] = $type;

        return $this;
    }

    /**
     * Get all of the created "drivers".
     *
     * @return array
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * Check if a reader exists
     *
     * @param string $name
     * @return boolean
     */
    public function hasType(string $name): bool
    {
        return !is_null($this->app['config']->get('mediatheque.types.' . $name)) ||
            isset($this->customTypes[$name]);
    }
}
